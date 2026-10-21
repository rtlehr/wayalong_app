<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\Person;
use App\Support\OrganizationContext;
use App\Support\OrganizationPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class OrganizationPersonController extends Controller
{
    private function organization(Request $request, OrganizationContext $context, OrganizationPermissions $permissions): Organization
    {
        $organization = $context->current($request);
        abort_unless($organization && $permissions->allows($request->user(), $organization, OrganizationPermissions::MANAGE_MEMBERS), 403);

        return $organization;
    }

    private function facilities(Organization $organization): array
    {
        return $organization->facilities()->orderBy('name')->get(['id', 'name'])->toArray();
    }

    public function index(Request $request, OrganizationContext $context, OrganizationPermissions $permissions): Response
    {
        $organization = $this->organization($request, $context, $permissions);
        $search = trim((string) $request->query('search', ''));
        abort_if(mb_strlen($search) > 100, 422);
        $people = Person::query()->with('facility:id,name')
            ->when($search !== '', fn ($q) => $q->where(fn ($inner) => $inner->where('first_name', 'like', '%'.$search.'%')->orWhere('last_name', 'like', '%'.$search.'%')))
            ->orderBy('last_name')->orderBy('first_name')->paginate(15)->withQueryString();

        return Inertia::render('organizations/People', ['organization' => $organization->only('id', 'name'), 'people' => $people, 'search' => $search]);
    }

    public function create(Request $request, OrganizationContext $context, OrganizationPermissions $permissions): Response
    {
        $organization = $this->organization($request, $context, $permissions);
        return Inertia::render('organizations/PersonForm', ['mode' => 'create', 'organization' => $organization->only('id', 'name'), 'facilities' => $this->facilities($organization), 'contacts' => []]);
    }

    public function show(Request $request, OrganizationContext $context, OrganizationPermissions $permissions, Person $person): Response
    {
        return $this->form($request, $context, $permissions, $person, 'view');
    }

    public function edit(Request $request, OrganizationContext $context, OrganizationPermissions $permissions, Person $person): Response
    {
        return $this->form($request, $context, $permissions, $person, 'edit');
    }

    private function form(Request $request, OrganizationContext $context, OrganizationPermissions $permissions, Person $person, string $mode): Response
    {
        $organization = $this->organization($request, $context, $permissions);
        return Inertia::render('organizations/PersonForm', [
            'mode' => $mode, 'organization' => $organization->only('id', 'name'),
            'person' => $person->only(['id', 'first_name', 'last_name', 'date_of_birth', 'person_type', 'facility_id', 'notes', 'user_id']),
            'facilities' => $this->facilities($organization),
            'contacts' => $person->contactMethods()->get(['kind', 'label', 'is_primary', 'value', 'line_1', 'line_2', 'city', 'state', 'postal_code', 'country'])->toArray(),
        ]);
    }

    private function validated(Request $request, Organization $organization): array
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'date_of_birth' => ['nullable', 'date', 'before_or_equal:today'],
            'person_type' => ['required', Rule::in(['client', 'guardian', 'staff', 'other'])],
            'facility_id' => ['nullable', 'integer', Rule::exists('facilities', 'id')->where('organization_id', $organization->id)],
            'notes' => ['nullable', 'string', 'max:4000'],
            'contacts' => ['sometimes', 'array', 'max:30'],
            'contacts.*.kind' => ['required', Rule::in(['email', 'phone', 'address'])],
            'contacts.*.label' => ['nullable', 'string', 'max:50'],
            'contacts.*.is_primary' => ['boolean'],
            'contacts.*.value' => ['nullable', 'string', 'max:255'],
            'contacts.*.line_1' => ['nullable', 'string', 'max:255'],
            'contacts.*.line_2' => ['nullable', 'string', 'max:255'],
            'contacts.*.city' => ['nullable', 'string', 'max:255'],
            'contacts.*.state' => ['nullable', 'string', 'max:50'],
            'contacts.*.postal_code' => ['nullable', 'string', 'max:20'],
            'contacts.*.country' => ['nullable', 'string', 'max:2'],
        ]);
        $seen = [];
        foreach ($data['contacts'] ?? [] as $contact) {
            if (($contact['kind'] ?? '') === 'email' && ! filter_var($contact['value'] ?? '', FILTER_VALIDATE_EMAIL)) {
                throw \Illuminate\Validation\ValidationException::withMessages(['contacts' => 'Contact email addresses must be valid.']);
            }
            if (($contact['kind'] ?? '') === 'address' ? empty($contact['line_1']) : empty($contact['value'])) {
                throw \Illuminate\Validation\ValidationException::withMessages(['contacts' => 'Every contact needs an address or value.']);
            }
            if (! empty($contact['is_primary'])) {
                if (isset($seen[$contact['kind']])) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['contacts' => 'Only one primary contact is allowed per type.']);
                }
                $seen[$contact['kind']] = true;
            }
        }
        return $data;
    }

    private function saveContacts(Person $person, array $contacts): void
    {
        $person->contactMethods()->delete();
        foreach ($contacts as $contact) {
            $person->contactMethods()->create($contact);
        }
    }

    public function store(Request $request, OrganizationContext $context, OrganizationPermissions $permissions): RedirectResponse
    {
        $organization = $this->organization($request, $context, $permissions);
        $data = $this->validated($request, $organization);
        $person = DB::transaction(function () use ($data): Person {
            $person = Person::create(collect($data)->except('contacts')->all());
            $this->saveContacts($person, $data['contacts'] ?? []);
            return $person;
        });
        return redirect()->route('organization.admin.people.show', $person)->with('success', 'Person added successfully. No login account was created.');
    }

    public function update(Request $request, OrganizationContext $context, OrganizationPermissions $permissions, Person $person): RedirectResponse
    {
        $organization = $this->organization($request, $context, $permissions);
        $data = $this->validated($request, $organization);
        DB::transaction(function () use ($person, $data): void {
            $person->update(collect($data)->except('contacts')->all());
            $this->saveContacts($person, $data['contacts'] ?? []);
        });
        return redirect()->route('organization.admin.people.edit', $person)->with('success', 'Person updated successfully.');
    }

    public function destroy(Request $request, OrganizationContext $context, OrganizationPermissions $permissions, Person $person): RedirectResponse
    {
        $this->organization($request, $context, $permissions);
        abort_if($person->user_id !== null, 422, 'Linked login records cannot be deleted here.');
        abort_if(\Illuminate\Support\Facades\DB::table('client_enrollments')->where('person_id', $person->id)->exists(), 422, 'Discharge and retain enrolled client records; deletion is not available here.');
        $person->delete();
        return redirect()->route('organization.admin.people.index')->with('success', 'Person removed.');
    }
}
