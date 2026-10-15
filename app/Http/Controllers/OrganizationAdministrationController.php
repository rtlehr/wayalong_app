<?php

namespace App\Http\Controllers;

use App\Models\Facility;
use App\Models\Organization;
use App\Support\OrganizationContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class OrganizationAdministrationController extends Controller
{
    private function authorizedOrganization(Request $request, OrganizationContext $context): Organization
    {
        $organization = $context->current($request);
        abort_unless($organization !== null, 403);

        $isAdmin = $request->user()->organizations()
            ->whereKey($organization->id)
            ->wherePivot('status', 'active')
            ->wherePivotIn('role', ['owner', 'admin'])
            ->exists();
        abort_unless($isAdmin, 403, 'Organization administrator access required.');

        return $organization;
    }

    public function index(Request $request, OrganizationContext $context): Response
    {
        $organization = $this->authorizedOrganization($request, $context);

        return Inertia::render('organizations/Admin', [
            'organization' => $organization->only('id', 'name', 'slug', 'status', 'timezone'),
            'facilities' => Facility::query()->orderBy('name')->get([
                'id', 'name', 'code', 'status', 'address_line_1', 'address_line_2',
                'city', 'state', 'postal_code', 'phone', 'email', 'timezone',
            ]),
            'members' => $organization->users()->orderBy('users.name')->get(['users.id', 'users.name', 'users.email'])
                ->map(fn ($user) => [
                    'id' => $user->id, 'name' => $user->name, 'email' => $user->email,
                    'role' => $user->pivot->role, 'status' => $user->pivot->status,
                ]),
        ]);
    }

    public function update(Request $request, OrganizationContext $context): RedirectResponse
    {
        $organization = $this->authorizedOrganization($request, $context);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'timezone' => ['required', 'timezone'],
        ]);
        $organization->update($data);

        return back()->with('success', 'Organization updated.');
    }

    /** @return array<string, mixed> */
    private function facilityData(Request $request, int $organizationId, ?int $facilityId = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', Rule::unique('facilities', 'code')
                ->where('organization_id', $organizationId)->ignore($facilityId)],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'address_line_1' => ['nullable', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'size:2'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'timezone' => ['nullable', 'timezone'],
        ]);
    }

    public function storeFacility(Request $request, OrganizationContext $context): RedirectResponse
    {
        $organization = $this->authorizedOrganization($request, $context);
        Facility::create($this->facilityData($request, $organization->id));

        return back()->with('success', 'Facility created.');
    }

    public function updateFacility(Request $request, OrganizationContext $context, int $facility): RedirectResponse
    {
        $organization = $this->authorizedOrganization($request, $context);
        $record = Facility::findOrFail($facility);
        $record->update($this->facilityData($request, $organization->id, $record->id));

        return back()->with('success', 'Facility updated.');
    }

    public function deleteFacility(Request $request, OrganizationContext $context, int $facility): RedirectResponse
    {
        $this->authorizedOrganization($request, $context);
        Facility::findOrFail($facility)->delete();

        return back()->with('success', 'Facility deleted.');
    }
}
