<?php

namespace App\Http\Controllers;

use App\Models\ClientEnrollment;
use App\Models\Person;
use App\Support\FacilityContext;
use App\Support\FacilityPermissions;
use App\Support\OrganizationContext;
use App\Support\PersonNameSearch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class FacilityClientController extends Controller
{
    private function authorizedFacility(Request $request, OrganizationContext $organizations, FacilityContext $facilities, FacilityPermissions $permissions, string $permission): \App\Models\Facility
    {
        $facility = $facilities->current($request, $organizations);
        abort_unless($facility !== null, 403, 'Select an assigned facility first.');
        abort_unless($permissions->allows($request->user(), $facility, $permission), 403, 'Facility permission required.');

        return $facility;
    }

    public function index(Request $request, OrganizationContext $organizations, FacilityContext $facilities, FacilityPermissions $permissions): Response
    {
        $facility = $this->authorizedFacility($request, $organizations, $facilities, $permissions, FacilityPermissions::VIEW_CLIENTS);
        $search = trim((string) $request->query('search', ''));
        abort_if(mb_strlen($search) > 100, 422);

        $canManage = $permissions->allows($request->user(), $facility, FacilityPermissions::MANAGE_CLIENTS);

        // Mirror the treatment overview's permission and client-assignment requirements.
        // Administrative client access alone must not advertise clinical actions.
        $hasTreatmentPermission = $permissions->allows($request->user(), $facility, FacilityPermissions::MANAGE_ALERTS)
            || $permissions->allows($request->user(), $facility, FacilityPermissions::VIEW_SHIFT_NOTES);
        $canViewAnyTreatment = $hasTreatmentPermission && $permissions->isOwner($request->user(), $facility);

        $enrollments = ClientEnrollment::query()
            ->with('person:id,first_name,last_name,date_of_birth')
            ->where('facility_id', $facility->id)
            ->where('organization_id', $facility->organization_id)
            ->when(! $canManage, fn ($query) => $query->whereExists(fn ($assignment) => $assignment->selectRaw('1')
                ->from('client_staff_assignments')
                ->whereColumn('client_staff_assignments.client_enrollment_id', 'client_enrollments.id')
                ->where('client_staff_assignments.organization_id', $facility->organization_id)
                ->where('client_staff_assignments.user_id', $request->user()->id)))
            ->whereHas('person', fn ($query) => $query->where('person_type', 'client'))
            ->when($search !== '', fn ($query) => $query->whereHas('person', fn ($person) => PersonNameSearch::constrain($person, $search, (int) $facility->organization_id)))
            ->orderByDesc('enrolled_at')->paginate(15)->withQueryString();

        $assignedClientIds = $hasTreatmentPermission && ! $canViewAnyTreatment
            ? DB::table('client_staff_assignments')
                ->where('organization_id', $facility->organization_id)
                ->where('user_id', $request->user()->id)
                ->whereIn('client_enrollment_id', $enrollments->getCollection()->pluck('id'))
                ->pluck('client_enrollment_id')->map(fn ($id) => (int) $id)->all()
            : [];
        $treatmentAccess = $enrollments->getCollection()->mapWithKeys(fn (ClientEnrollment $enrollment) => [
            $enrollment->id => $enrollment->status === 'active'
                && $hasTreatmentPermission
                && ($canViewAnyTreatment || in_array((int) $enrollment->id, $assignedClientIds, true)),
        ])->all();

        return Inertia::render('facilities/Clients', [
            'facility' => $facility->only('id', 'name'),
            'clients' => $enrollments,
            'search' => $search,
            'canManage' => $canManage,
            'treatmentAccess' => $treatmentAccess,
        ]);
    }

    public function create(Request $request, OrganizationContext $organizations, FacilityContext $facilities, FacilityPermissions $permissions): Response
    {
        $facility = $this->authorizedFacility($request, $organizations, $facilities, $permissions, FacilityPermissions::MANAGE_CLIENTS);

        return Inertia::render('facilities/ClientForm', ['facility' => $facility->only('id', 'name')]);
    }

    public function store(Request $request, OrganizationContext $organizations, FacilityContext $facilities, FacilityPermissions $permissions): RedirectResponse
    {
        $facility = $this->authorizedFacility($request, $organizations, $facilities, $permissions, FacilityPermissions::MANAGE_CLIENTS);
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'date_of_birth' => ['nullable', 'date', 'before_or_equal:today'],
            'enrolled_at' => ['required', 'date', 'before_or_equal:today'],
        ]);

        DB::transaction(function () use ($data, $facility): void {
            $person = Person::create([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'date_of_birth' => $data['date_of_birth'] ?? null,
                'person_type' => 'client',
                'facility_id' => $facility->id,
            ]);
            ClientEnrollment::create([
                'person_id' => $person->id,
                'facility_id' => $facility->id,
                'status' => 'active',
                'enrolled_at' => $data['enrolled_at'],
            ]);
        });

        return redirect()->route('facility.clients.index')->with('success', 'Client enrolled. No email or login account was required.');
    }

    private function clientEnrollment(Request $request, ClientEnrollment $enrollment, OrganizationContext $organizations, FacilityContext $facilities, FacilityPermissions $permissions, string $permission): array
    {
        $facility = $this->authorizedFacility($request, $organizations, $facilities, $permissions, $permission);
        abort_unless((int) $enrollment->facility_id === (int) $facility->id
            && (int) $enrollment->organization_id === (int) $facility->organization_id, 404);
        if ($permission === FacilityPermissions::VIEW_CLIENTS
            && ! $permissions->allows($request->user(), $facility, FacilityPermissions::MANAGE_CLIENTS)) {
            abort_unless(DB::table('client_staff_assignments')
                ->where('organization_id', $facility->organization_id)
                ->where('client_enrollment_id', $enrollment->id)
                ->where('user_id', $request->user()->id)->exists(), 403);
        }
        $person = $enrollment->person;
        abort_unless($person && $person->person_type === 'client' && (int) $person->organization_id === (int) $facility->organization_id, 404);

        return [$facility, $person];
    }

    public function show(Request $request, ClientEnrollment $enrollment, OrganizationContext $organizations, FacilityContext $facilities, FacilityPermissions $permissions): Response
    {
        return $this->profile($request, $enrollment, $organizations, $facilities, $permissions, false);
    }

    public function edit(Request $request, ClientEnrollment $enrollment, OrganizationContext $organizations, FacilityContext $facilities, FacilityPermissions $permissions): Response
    {
        return $this->profile($request, $enrollment, $organizations, $facilities, $permissions, true);
    }

    private function profile(Request $request, ClientEnrollment $enrollment, OrganizationContext $organizations, FacilityContext $facilities, FacilityPermissions $permissions, bool $editing): Response
    {
        [$facility, $person] = $this->clientEnrollment($request, $enrollment, $organizations, $facilities, $permissions, $editing ? FacilityPermissions::MANAGE_CLIENTS : FacilityPermissions::VIEW_CLIENTS);
        $canManage = $permissions->allows($request->user(), $facility, FacilityPermissions::MANAGE_CLIENTS);
        $guardianIds = DB::table('client_guardian_relationships')->where('client_enrollment_id', $enrollment->id)->pluck('guardian_person_id')->all();
        $guardians = Person::query()->whereIn('id', $guardianIds)->with('contactMethods')->get(['id', 'first_name', 'last_name']);
        $guardianDetails = DB::table('client_guardian_relationships')->where('client_enrollment_id', $enrollment->id)
            ->get(['guardian_person_id', 'relationship_label', 'is_emergency_contact', 'notes'])->keyBy('guardian_person_id');
        $guardians = $guardians->map(function (Person $guardian) use ($guardianDetails) {
            $details = $guardianDetails->get($guardian->id);
            return [
                'id' => $guardian->id,
                'first_name' => $guardian->first_name,
                'last_name' => $guardian->last_name,
                'relationship_label' => $details?->relationship_label ?? 'Guardian',
                'is_emergency_contact' => (bool) ($details?->is_emergency_contact ?? false),
                'notes' => $details?->notes,
                'contacts' => $guardian->contactMethods->map->only(['kind', 'label', 'is_primary', 'value', 'line_1', 'line_2', 'city', 'state', 'postal_code', 'country'])->values()->all(),
            ];
        });
        $authorizationRecords = DB::table('guardian_authorization_records')
            ->join('users as recorders', 'recorders.id', '=', 'guardian_authorization_records.recorded_by_user_id')
            ->where('guardian_authorization_records.client_enrollment_id', $enrollment->id)
            ->where('guardian_authorization_records.organization_id', $facility->organization_id)
            ->orderByDesc('guardian_authorization_records.id')
            ->get([
                'guardian_authorization_records.id', 'guardian_authorization_records.guardian_person_id',
                'guardian_authorization_records.record_type', 'guardian_authorization_records.subject',
                'guardian_authorization_records.decision', 'guardian_authorization_records.effective_on',
                'guardian_authorization_records.expires_on', 'guardian_authorization_records.evidence_reference', 'guardian_authorization_records.evidence_document_id',
                'guardian_authorization_records.notes', 'guardian_authorization_records.created_at',
                'recorders.name as recorded_by',
            ]);
        $staffIds = DB::table('client_staff_assignments')->where('client_enrollment_id', $enrollment->id)->pluck('user_id')->all();
        $staff = DB::table('users')->whereIn('id', $staffIds)->get(['id', 'name']);

        app(\App\Support\SensitiveAccessAudit::class)->record($request, $enrollment, 'admin_profile');

        return Inertia::render('facilities/ClientProfile', [
            'mode' => $editing ? 'edit' : 'view', 'canManage' => $canManage,
            'facility' => $facility->only('id', 'name'),
            'enrollment' => $enrollment->only('id', 'status', 'enrolled_at', 'discharged_at'),
            'person' => $person->only('id', 'first_name', 'last_name', 'date_of_birth'),
            'canAccessBilling' => (function () use ($request, $facility): bool {
                $membership = $request->user()->organizations()->whereKey($facility->organization_id)
                    ->where('organizations.status', 'active')->wherePivot('status', 'active')->first();
                return $membership && in_array($membership->pivot->role, ['owner', 'admin'], true)
                    && DB::table('organization_staff_facilities')->where('organization_id', $facility->organization_id)
                        ->where('facility_id', $facility->id)->where('user_id', $request->user()->id)->exists();
            })(),
            'insuranceProviders' => \App\Models\InsuranceProvider::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'payer_code', 'phone']),
            'insurancePolicies' => \App\Models\ClientInsurancePolicy::query()->with('verifications')->where('person_id', $person->id)
                ->orderByRaw("CASE coverage_order WHEN 'primary' THEN 1 WHEN 'secondary' THEN 2 ELSE 3 END")
                ->get(['id', 'insurance_provider_id', 'payer_name', 'coverage_order', 'member_id', 'group_number', 'plan_name', 'subscriber_name', 'subscriber_relationship', 'effective_on', 'expires_on', 'verification_status', 'verified_on', 'notes']),
            'contacts' => $person->contactMethods()->get(['kind', 'label', 'is_primary', 'value', 'line_1', 'line_2', 'city', 'state', 'postal_code', 'country'])->toArray(),
            'guardians' => $guardians,
            'authorizationRecords' => $authorizationRecords,
            'documents' => app(\App\Support\DocumentManager::class)
                ->forRecord(\App\Support\DocumentManager::CLIENT_ENROLLMENT, (int) $enrollment->id,
                    (int) $facility->organization_id, (int) $facility->id)
                ->map(fn ($doc) => [
                    'id' => $doc->id, 'title' => $doc->title, 'category' => $doc->category,
                    'current_version' => $doc->current_version,
                    'versions' => $doc->versions()->get(['id', 'version_number', 'original_filename', 'file_size', 'change_notes', 'created_at'])->map(fn ($version) => [
                        'id' => $version->id, 'number' => $version->version_number,
                        'filename' => $version->original_filename, 'notes' => $version->change_notes,
                        'created_at' => $version->created_at?->toIso8601String(),
                    ])->all(),
                    'original_filename' => $doc->original_filename,
                    'file_size' => $doc->file_size, 'expires_on' => $doc->expires_on?->toDateString(),
                    'created_at' => $doc->created_at?->toIso8601String(),
                    'review_status' => $doc->review_status, 'review_notes' => $doc->review_notes,
                    'reviewed_at' => $doc->reviewed_at?->toIso8601String(),
                    'review_events' => \Illuminate\Support\Facades\DB::table('document_review_events')
                        ->where('document_id', $doc->id)->orderByDesc('id')->get(['status','notes','created_at'])->toArray(),
                ])->values()->all(),

            'assignedStaff' => $staff,
            'guardianOptions' => $editing ? Person::query()->where('person_type', 'guardian')->get(['id', 'first_name', 'last_name'])->sortBy(fn (Person $guardian) => mb_strtolower($guardian->last_name.' '.$guardian->first_name))->values() : [],
            'staffOptions' => $editing ? DB::table('organization_staff_facilities as assignments')
                ->join('users', 'users.id', '=', 'assignments.user_id')
                ->join('organization_user', function ($join) { $join->on('organization_user.user_id', '=', 'users.id')->on('organization_user.organization_id', '=', 'assignments.organization_id'); })
                ->where('assignments.organization_id', $facility->organization_id)
                ->where('assignments.facility_id', $facility->id)
                ->where('organization_user.status', 'active')
                ->orderBy('users.name')->distinct()->get(['users.id', 'users.name']) : [],
        ]);
    }

    public function createGuardian(Request $request, ClientEnrollment $enrollment, OrganizationContext $organizations, FacilityContext $facilities, FacilityPermissions $permissions): RedirectResponse
    {
        [$facility] = $this->clientEnrollment($request, $enrollment, $organizations, $facilities, $permissions, FacilityPermissions::MANAGE_CLIENTS);
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'relationship_label' => ['required', Rule::in(['Parent', 'Legal guardian', 'Foster parent', 'Relative', 'Other', 'Guardian'])],
            'is_emergency_contact' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'phone' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:50'],
            'postal_code' => ['nullable', 'string', 'max:20'],
        ]);
        DB::transaction(function () use ($facility, $enrollment, $data): void {
            $guardian = Person::create([
                'first_name' => $data['first_name'], 'last_name' => $data['last_name'],
                'person_type' => 'guardian', 'facility_id' => $facility->id,
            ]);
            foreach (['phone', 'email'] as $kind) {
                if (filled($data[$kind] ?? null)) {
                    $guardian->contactMethods()->create([
                        'kind' => $kind, 'label' => $kind === 'phone' ? 'Mobile' : 'Personal',
                        'value' => $data[$kind], 'is_primary' => true,
                    ]);
                }
            }
            if (filled($data['address'] ?? null)) {
                $guardian->contactMethods()->create([
                    'kind' => 'address', 'label' => 'Home', 'line_1' => $data['address'],
                    'city' => $data['city'] ?? null, 'state' => $data['state'] ?? null,
                    'postal_code' => $data['postal_code'] ?? null, 'country' => 'US', 'is_primary' => true,
                ]);
            }
            DB::table('client_guardian_relationships')->insert([
                'organization_id' => $facility->organization_id,
                'client_enrollment_id' => $enrollment->id, 'guardian_person_id' => $guardian->id,
                'relationship_label' => $data['relationship_label'],
                'is_emergency_contact' => (bool) ($data['is_emergency_contact'] ?? false),
                'notes' => $data['notes'] ?? null, 'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        return redirect()->route('facility.clients.edit', $enrollment)->with('success', 'Parent or guardian created and linked to the client.');
    }

    public function recordGuardianAuthorization(Request $request, ClientEnrollment $enrollment, OrganizationContext $organizations, FacilityContext $facilities, FacilityPermissions $permissions): RedirectResponse
    {
        [$facility] = $this->clientEnrollment($request, $enrollment, $organizations, $facilities, $permissions, FacilityPermissions::MANAGE_CLIENTS);
        $data = $request->validate([
            'guardian_person_id' => ['required', 'integer'],
            'record_type' => ['required', Rule::in(['authority', 'consent'])],
            'subject' => ['required', Rule::in(['legal_authority', 'treatment', 'information_sharing', 'emergency_care', 'communication', 'other'])],
            'decision' => ['required', Rule::in(['pending', 'verified', 'restricted', 'revoked', 'granted', 'declined', 'withdrawn'])],
            'effective_on' => ['required', 'date', 'before_or_equal:today'],
            'expires_on' => ['nullable', 'date', 'after_or_equal:effective_on'],
            'evidence_reference' => ['nullable', 'string', 'max:255'],
            'evidence_document_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        if (($data['record_type'] === 'authority' && ($data['subject'] !== 'legal_authority' || ! in_array($data['decision'], ['pending', 'verified', 'restricted', 'revoked'], true))) ||
            ($data['record_type'] === 'consent' && ($data['subject'] === 'legal_authority' || ! in_array($data['decision'], ['granted', 'declined', 'withdrawn'], true)))) {
            throw ValidationException::withMessages(['decision' => 'Select a decision and subject appropriate to the record type.']);
        }
        if (in_array($data['decision'], ['verified', 'granted'], true) && blank($data['evidence_reference'] ?? null) && empty($data['evidence_document_id'])) {
            throw ValidationException::withMessages(['evidence_reference' => 'Enter a reference to the supporting documentation.']);
        }
        $linked = DB::table('client_guardian_relationships')
            ->where('organization_id', $facility->organization_id)
            ->where('client_enrollment_id', $enrollment->id)
            ->where('guardian_person_id', $data['guardian_person_id'])
            ->exists();
        abort_unless($linked, 404);

        // An evidence document must belong to this exact enrollment and facility.
        if (! empty($data['evidence_document_id'])) {
            $evidence = \App\Models\Document::query()
                ->whereKey($data['evidence_document_id'])
                ->where('organization_id', $facility->organization_id)
                ->where('facility_id', $facility->id)
                ->whereHas('attachments', fn ($query) => $query
                    ->where('record_type', \App\Support\DocumentManager::CLIENT_ENROLLMENT)
                    ->where('record_id', $enrollment->id))->first();
            abort_unless($evidence !== null, 404);
        }

        DB::table('guardian_authorization_records')->insert([
            'organization_id' => $facility->organization_id,
            'client_enrollment_id' => $enrollment->id,
            'guardian_person_id' => $data['guardian_person_id'],
            'recorded_by_user_id' => $request->user()->id,
            'record_type' => $data['record_type'],
            'subject' => $data['subject'],
            'decision' => $data['decision'],
            'effective_on' => $data['effective_on'],
            'expires_on' => $data['expires_on'] ?? null,
            'evidence_reference' => $data['evidence_reference'] ?? null,
            'evidence_document_id' => $data['evidence_document_id'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return redirect()->route('facility.clients.edit', $enrollment)
            ->with('success', 'Authorization or consent record saved to the history. No access permissions were changed.');
    }

    public function showGuardian(Request $request, ClientEnrollment $enrollment, Person $guardian, OrganizationContext $organizations, FacilityContext $facilities, FacilityPermissions $permissions): Response
    {
        [$facility] = $this->clientEnrollment($request, $enrollment, $organizations, $facilities, $permissions, FacilityPermissions::VIEW_CLIENTS);
        abort_unless((int) $guardian->organization_id === (int) $facility->organization_id && $guardian->person_type === 'guardian', 404);
        abort_unless(DB::table('client_guardian_relationships')
            ->where('client_enrollment_id', $enrollment->id)->where('guardian_person_id', $guardian->id)->exists(), 404);

        return Inertia::render('facilities/ClientGuardianProfile', [
            'enrollmentId' => $enrollment->id,
            'guardian' => $guardian->only('id', 'first_name', 'last_name', 'date_of_birth', 'notes'),
            'contacts' => $guardian->contactMethods()->get(['kind', 'label', 'is_primary', 'value', 'line_1', 'line_2', 'city', 'state', 'postal_code', 'country']),
        ]);
    }

    public function saveProfile(Request $request, ClientEnrollment $enrollment, OrganizationContext $organizations, FacilityContext $facilities, FacilityPermissions $permissions): RedirectResponse
    {
        [$facility, $person] = $this->clientEnrollment($request, $enrollment, $organizations, $facilities, $permissions, FacilityPermissions::MANAGE_CLIENTS);
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'date_of_birth' => ['nullable', 'date', 'before_or_equal:today'],
            'contacts' => ['array', 'max:30'],
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
            'guardian_ids' => ['array', 'max:30'],
            'guardian_ids.*' => ['integer', 'distinct', Rule::exists('people', 'id')->where('organization_id', $facility->organization_id)->where('person_type', 'guardian')],
            'guardian_details' => ['sometimes', 'array', 'max:30'],
            'guardian_details.*.relationship_label' => ['required', Rule::in(['Parent', 'Legal guardian', 'Foster parent', 'Relative', 'Other', 'Guardian'])],
            'guardian_details.*.is_emergency_contact' => ['boolean'],
            'guardian_details.*.notes' => ['nullable', 'string', 'max:1000'],
            'staff_ids' => ['array', 'max:100'],
            'staff_ids.*' => ['integer', 'distinct'],
        ]);
        $primary = [];
        foreach ($data['contacts'] ?? [] as $contact) {
            $kind = $contact['kind'];
            if ($kind === 'email' && ! filter_var($contact['value'] ?? '', FILTER_VALIDATE_EMAIL)) {
                throw ValidationException::withMessages(['contacts' => 'Enter a valid email address or remove the email entry.']);
            }
            if ($kind === 'address' ? empty($contact['line_1']) : empty($contact['value'])) {
                throw ValidationException::withMessages(['contacts' => 'Each contact requires a value or street address.']);
            }
            if (! empty($contact['is_primary'])) {
                if (isset($primary[$kind])) {
                    throw ValidationException::withMessages(['contacts' => 'Only one primary contact is allowed per type.']);
                }
                $primary[$kind] = true;
            }
        }
        $guardianIds = array_map('intval', $data['guardian_ids'] ?? []);
        $guardianDetails = $data['guardian_details'] ?? [];
        // Details must refer only to guardians included in this enrollment submission.
        foreach (array_keys($guardianDetails) as $guardianId) {
            if (! ctype_digit((string) $guardianId) || ! in_array((int) $guardianId, $guardianIds, true)) {
                throw ValidationException::withMessages(['guardian_details' => 'Guardian details must belong to a selected guardian.']);
            }
        }
        $staffIds = $data['staff_ids'] ?? [];
        $validStaff = DB::table('organization_staff_facilities as assignment')
            ->join('organization_user as membership', function ($join) { $join->on('membership.user_id', '=', 'assignment.user_id')->on('membership.organization_id', '=', 'assignment.organization_id'); })
            ->where('assignment.organization_id', $facility->organization_id)->where('assignment.facility_id', $facility->id)
            ->where('membership.status', 'active')->whereIn('assignment.user_id', $staffIds)
            ->distinct()->pluck('assignment.user_id')->all();
        if (count($validStaff) !== count($staffIds)) {
            throw ValidationException::withMessages(['staff_ids' => 'Every assigned staff member must belong to this facility and have an active organization membership.']);
        }
        $assignmentChanges = DB::transaction(function () use ($data, $person, $enrollment, $facility, $staffIds, $guardianDetails): array {
            // Serialize edits for this enrollment so the audit delta reflects committed assignments.
            DB::table('client_enrollments')->where('id', $enrollment->id)->lockForUpdate()->first();
            $previousStaffIds = DB::table('client_staff_assignments')
                ->where('organization_id', $facility->organization_id)
                ->where('client_enrollment_id', $enrollment->id)
                ->pluck('user_id')->map(fn ($id) => (int) $id)->all();
            $newStaffIds = array_values(array_unique(array_map('intval', $staffIds)));
            $added = array_values(array_diff($newStaffIds, $previousStaffIds));
            $removed = array_values(array_diff($previousStaffIds, $newStaffIds));
            $person->update(collect($data)->only(['first_name', 'last_name', 'date_of_birth'])->all());
            $person->contactMethods()->delete();
            foreach ($data['contacts'] ?? [] as $contact) {
                $person->contactMethods()->create($contact);
            }
            DB::table('client_guardian_relationships')->where('client_enrollment_id', $enrollment->id)->delete();
            foreach ($data['guardian_ids'] ?? [] as $guardianId) {
                DB::table('client_guardian_relationships')->insert(['organization_id' => $facility->organization_id, 'client_enrollment_id' => $enrollment->id, 'guardian_person_id' => $guardianId,
                    'relationship_label' => $guardianDetails[$guardianId]['relationship_label'] ?? 'Guardian',
                    'is_emergency_contact' => (bool) ($guardianDetails[$guardianId]['is_emergency_contact'] ?? false),
                    'notes' => $guardianDetails[$guardianId]['notes'] ?? null,
                    'created_at' => now(), 'updated_at' => now()]);
            }
            DB::table('client_staff_assignments')->where('client_enrollment_id', $enrollment->id)->delete();
            foreach ($staffIds as $staffId) {
                DB::table('client_staff_assignments')->insert(['organization_id' => $facility->organization_id, 'client_enrollment_id' => $enrollment->id, 'user_id' => $staffId, 'created_at' => now(), 'updated_at' => now()]);
            }
            return ['added' => $added, 'removed' => $removed];
        });

        foreach (['added' => 'created', 'removed' => 'removed'] as $key => $action) {
            foreach ($assignmentChanges[$key] as $staffId) {
                Log::info('WayAlong client staff assignment '.$action, [
                    'actor_id' => (int) $request->user()->id,
                    'subject_id' => (int) $staffId,
                    'organization_id' => (int) $facility->organization_id,
                    'facility_id' => (int) $facility->id,
                    'client_enrollment_id' => (int) $enrollment->id,
                    'action' => 'client_staff_assignment.'.$action,
                ]);
            }
        }

        return redirect()->route('facility.clients.edit', $enrollment)->with('success', 'Client profile updated.');
    }

    public function update(Request $request, ClientEnrollment $enrollment, OrganizationContext $organizations, FacilityContext $facilities, FacilityPermissions $permissions): RedirectResponse
    {
        $facility = $this->authorizedFacility($request, $organizations, $facilities, $permissions, FacilityPermissions::MANAGE_CLIENTS);
        abort_unless((int) $enrollment->facility_id === (int) $facility->id, 404);
        $data = $request->validate([
            'status' => ['required', Rule::in(['active', 'discharged'])],
            'discharged_at' => ['nullable', 'required_if:status,discharged', 'date', 'after_or_equal:'.$enrollment->enrolled_at->toDateString(), 'before_or_equal:today'],
        ]);
        $enrollment->update([
            'status' => $data['status'],
            'discharged_at' => $data['status'] === 'discharged' ? $data['discharged_at'] : null,
        ]);

        return redirect()->route('facility.clients.index')->with('success', 'Enrollment status updated.');
    }
}
