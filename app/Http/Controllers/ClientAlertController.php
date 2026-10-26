<?php

namespace App\Http\Controllers;

use App\Models\ClientAlert;
use App\Models\ClientAlertEvent;
use App\Models\ClientEnrollment;
use App\Support\FacilityPermissions;
use App\Support\OrganizationContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ClientAlertController extends Controller
{
    private function authorizeAssignedClinician(Request $request, ClientEnrollment $enrollment, FacilityPermissions $permissions, OrganizationContext $organizations): void
    {
        $organization = $organizations->current($request);
        $facility = $enrollment->facility;
        abort_unless($organization && $facility && $facility->status === 'active'
            && (int) $enrollment->organization_id === (int) $organization->id
            && (int) $facility->organization_id === (int) $organization->id
            && $enrollment->status === 'active'
            && $enrollment->person?->person_type === 'client', 404);
        abort_unless($permissions->allows($request->user(), $facility, FacilityPermissions::MANAGE_ALERTS), 403);
        abort_unless(DB::table('client_staff_assignments')
            ->where('organization_id', $organization->id)
            ->where('client_enrollment_id', $enrollment->id)
            ->where('user_id', $request->user()->id)->exists(), 403);
    }

    public function store(Request $request, ClientEnrollment $enrollment, FacilityPermissions $permissions, OrganizationContext $organizations): RedirectResponse
    {
        $this->authorizeAssignedClinician($request, $enrollment, $permissions, $organizations);
        $data = $request->validate([
            'category' => ['required', Rule::in(['medical', 'behavioral', 'general'])],
            'priority' => ['required', Rule::in(['high', 'warning', 'follow_up'])],
            'title' => ['required', 'string', 'max:160'],
            'details' => ['nullable', 'string', 'max:3000'],
        ]);
        DB::transaction(function () use ($data, $enrollment, $request): void {
            $alert = ClientAlert::create([...$data, 'client_enrollment_id' => $enrollment->id, 'created_by' => $request->user()->id]);
            ClientAlertEvent::create(['client_alert_id' => $alert->id, 'actor_id' => $request->user()->id, 'action' => 'created', 'changes' => ['original' => $alert->only(['category', 'priority', 'title', 'details'])]]);
        });
        return back()->with('success', 'Client alert recorded.');
    }

    public function update(Request $request, ClientEnrollment $enrollment, ClientAlert $alert, FacilityPermissions $permissions, OrganizationContext $organizations): RedirectResponse
    {
        $this->authorizeAssignedClinician($request, $enrollment, $permissions, $organizations);
        abort_unless((int) $alert->client_enrollment_id === (int) $enrollment->id, 404);
        abort_if($alert->resolved_at !== null, 422, 'Resolved alerts cannot be updated.');

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:3000'],
            'category' => ['sometimes', 'required', Rule::in(['medical', 'behavioral', 'general'])],
            'priority' => ['sometimes', 'required', Rule::in(['high', 'warning', 'follow_up'])],
            'title' => ['sometimes', 'required', 'string', 'max:160'],
            'details' => ['sometimes', 'nullable', 'string', 'max:3000'],
        ]);

        DB::transaction(function () use ($alert, $request, $data): void {
            $locked = ClientAlert::query()->whereKey($alert->id)->lockForUpdate()->firstOrFail();
            abort_if($locked->resolved_at !== null, 422, 'Resolved alerts cannot be updated.');
            $before = $locked->only(['category', 'priority', 'title', 'details']);
            $updates = array_intersect_key($data, array_flip(['category', 'priority', 'title', 'details']));
            $locked->fill($updates);
            $changes = [];
            foreach ($before as $field => $oldValue) {
                $newValue = $locked->{$field};
                if ($oldValue !== $newValue) {
                    $changes[$field] = ['from' => $oldValue, 'to' => $newValue];
                }
            }
            if ($changes !== []) {
                $locked->save();
            }
            ClientAlertEvent::create([
                'client_alert_id' => $locked->id,
                'actor_id' => $request->user()->id,
                'action' => 'updated',
                'reason' => trim($data['reason']),
                'changes' => $changes ?: null,
            ]);
        });

        return back()->with('success', 'Alert progress update recorded.');
    }

    public function resolve(Request $request, ClientEnrollment $enrollment, ClientAlert $alert, FacilityPermissions $permissions, OrganizationContext $organizations): RedirectResponse
    {
        $this->authorizeAssignedClinician($request, $enrollment, $permissions, $organizations);
        abort_unless((int) $alert->client_enrollment_id === (int) $enrollment->id, 404);
        abort_if($alert->resolved_at !== null, 422, 'Alert has already been resolved.');
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:3000']]);
        DB::transaction(function () use ($alert, $request, $data): void {
            // The conditional update ensures a double submission cannot create two resolution events.
            $updated = ClientAlert::query()->whereKey($alert->id)->whereNull('resolved_at')->update([
                'resolved_at' => now(), 'resolved_by' => $request->user()->id,
            ]);
            abort_unless($updated === 1, 422, 'Alert has already been resolved.');
            ClientAlertEvent::create([
                'client_alert_id' => $alert->id, 'actor_id' => $request->user()->id,
                'action' => 'resolved', 'reason' => trim($data['reason']),
            ]);
        });
        return back()->with('success', 'Client alert resolved.');
    }
}
