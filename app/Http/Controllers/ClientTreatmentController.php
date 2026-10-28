<?php

namespace App\Http\Controllers;

use App\Models\ClientEnrollment;
use App\Support\FacilityPermissions;
use App\Support\OrganizationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ClientTreatmentController extends Controller
{
    public function overview(Request $request, ClientEnrollment $enrollment, FacilityPermissions $permissions, OrganizationContext $organizations): Response
    {
        $organization = $organizations->current($request);
        $facility = $enrollment->facility;
        abort_unless($organization && $facility && $facility->status === 'active'
            && (int) $enrollment->organization_id === (int) $organization->id
            && (int) $facility->organization_id === (int) $organization->id
            && $enrollment->status === 'active'
            && $enrollment->person?->person_type === 'client', 404);
        abort_unless($permissions->isOwner($request->user(), $facility) || DB::table('client_staff_assignments')
            ->where('organization_id', $organization->id)
            ->where('client_enrollment_id', $enrollment->id)
            ->where('user_id', $request->user()->id)->exists(), 403);

        $canViewAlerts = $permissions->allows($request->user(), $facility, FacilityPermissions::MANAGE_ALERTS);
        $canViewShiftNotes = $permissions->allows($request->user(), $facility, FacilityPermissions::VIEW_SHIFT_NOTES);
        abort_unless($canViewAlerts || $canViewShiftNotes, 403);

        $alerts = $canViewAlerts ? $enrollment->activeAlerts()
            ->orderByRaw("CASE priority WHEN 'high' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END")
            ->orderByDesc('created_at')->limit(5)->get() : collect();
        $note = $canViewShiftNotes ? $enrollment->latestShiftNote()->with('author:id,name')->first() : null;

        return Inertia::render('facilities/ClientTreatmentOverview', [
            'client' => [
                'id' => $enrollment->id,
                'name' => trim($enrollment->person->first_name.' '.$enrollment->person->last_name),
                'facility' => $facility->name,
            ],
            'canViewAlerts' => $canViewAlerts,
            'canViewShiftNotes' => $canViewShiftNotes,
            'activeAlertCount' => $canViewAlerts ? $enrollment->activeAlerts()->count() : null,
            'alerts' => $alerts->map(fn ($alert) => [
                'id' => $alert->id,
                'title' => $alert->title,
                'category' => $alert->category,
                'priority' => $alert->priority,
                'details' => $alert->details,
                'created_at' => $alert->created_at?->toDateTimeString(),
            ])->values()->all(),
            'latestShiftNote' => $note ? [
                'id' => $note->id,
                'shift_date' => $note->shift_date?->toDateString(),
                'shift' => $note->shift,
                'summary' => $note->summary,
                'handoff' => $note->handoff,
                'author_name' => $note->author?->name ?? 'Former staff member',
                'created_at' => $note->created_at?->toDateTimeString(),
            ] : null,
        ]);
    }

    public function alerts(Request $request, ClientEnrollment $enrollment, FacilityPermissions $permissions, OrganizationContext $organizations): Response
    {
        $organization = $organizations->current($request);
        $facility = $enrollment->facility;
        abort_unless($organization && $facility && $facility->status === 'active'
            && (int) $enrollment->organization_id === (int) $organization->id
            && (int) $facility->organization_id === (int) $organization->id
            && $enrollment->status === 'active'
            && $enrollment->person?->person_type === 'client', 404);
        abort_unless($permissions->allows($request->user(), $facility, FacilityPermissions::MANAGE_ALERTS), 403);
        abort_unless($permissions->isOwner($request->user(), $facility) || DB::table('client_staff_assignments')
            ->where('organization_id', $organization->id)
            ->where('client_enrollment_id', $enrollment->id)
            ->where('user_id', $request->user()->id)->exists(), 403);

        $alerts = $enrollment->alertHistory()->with(['events' => fn ($query) => $query->with('actor:id,name')->orderBy('id')])
            ->orderByDesc('created_at')->orderByDesc('id')->get();

        return Inertia::render('facilities/ClientTreatment', [
            'client' => [
                'id' => $enrollment->id,
                'name' => trim($enrollment->person->first_name.' '.$enrollment->person->last_name),
                'facility' => $facility->name,
            ],
            'alerts' => $alerts->map(fn ($alert) => [
                'id' => $alert->id,
                'title' => $alert->title,
                'category' => $alert->category,
                'priority' => $alert->priority,
                'details' => $alert->details,
                'created_at' => $alert->created_at?->toDateTimeString(),
                'resolved_at' => $alert->resolved_at?->toDateTimeString(),
                'events' => $alert->events->map(fn ($event) => [
                    'id' => $event->id,
                    'action' => $event->action,
                    'reason' => $event->reason,
                    'changes' => $event->changes,
                    'actor_id' => $event->actor_id,
                    'actor_name' => $event->actor?->name ?? 'Former staff member',
                    'created_at' => $event->created_at?->toDateTimeString(),
                ])->values()->all(),
            ])->values()->all(),
        ]);
    }
}
