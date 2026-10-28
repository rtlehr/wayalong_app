<?php

namespace App\Http\Controllers;

use App\Models\ClientEnrollment;
use App\Models\ClientShiftNote;
use App\Models\ClientShiftNoteAcknowledgment;
use App\Support\FacilityPermissions;
use App\Support\OrganizationContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ClientShiftNoteController extends Controller
{
    private function authorizeAssigned(Request $request, ClientEnrollment $enrollment, FacilityPermissions $permissions, OrganizationContext $organizations, string $permission): void
    {
        $organization = $organizations->current($request);
        $facility = $enrollment->facility;
        abort_unless($organization && $facility && $facility->status === 'active'
            && (int) $enrollment->organization_id === (int) $organization->id
            && (int) $facility->organization_id === (int) $organization->id
            && $enrollment->status === 'active'
            && $enrollment->person?->person_type === 'client', 404);
        abort_unless($permissions->allows($request->user(), $facility, $permission), 403);
        abort_unless($permissions->isOwner($request->user(), $facility) || DB::table('client_staff_assignments')->where('organization_id', $organization->id)
            ->where('client_enrollment_id', $enrollment->id)->where('user_id', $request->user()->id)->exists(), 403);
    }

    public function index(Request $request, ClientEnrollment $enrollment, FacilityPermissions $permissions, OrganizationContext $organizations): Response
    {
        $this->authorizeAssigned($request, $enrollment, $permissions, $organizations, FacilityPermissions::VIEW_SHIFT_NOTES);
        $notes = $enrollment->shiftNotes()->with(['author:id,name', 'acknowledgments.user:id,name'])->orderByDesc('id')->paginate(20);
        return Inertia::render('facilities/ClientShiftNotes', [
            'client' => ['id' => $enrollment->id, 'name' => trim($enrollment->person->first_name.' '.$enrollment->person->last_name), 'facility' => $enrollment->facility->name],
            'notes' => $notes->through(fn (ClientShiftNote $note) => [
                'id' => $note->id,
                'shift_date' => $note->shift_date?->toDateString(),
                'shift' => $note->shift,
                'summary' => $note->summary,
                'observations' => $note->observations,
                'significant_events' => $note->significant_events,
                'interventions' => $note->interventions,
                'handoff' => $note->handoff,
                'author_id' => $note->author_id,
                'author_name' => $note->author?->name ?? 'Former staff member',
                'created_at' => $note->created_at?->toDateTimeString(),
                'acknowledged_by_me' => $note->acknowledgments->contains('user_id', $request->user()->id),
                'acknowledgment_count' => $note->acknowledgments->count(),
                'acknowledgments' => $note->acknowledgments->map(fn ($ack) => [
                    'user_name' => $ack->user?->name ?? 'Former staff member',
                    'acknowledged_at' => $ack->acknowledged_at?->toDateTimeString(),
                ])->values()->all(),
            ]),
            'currentUserId' => $request->user()->id,
            'canWrite' => $permissions->allows($request->user(), $enrollment->facility, FacilityPermissions::WRITE_SHIFT_NOTES),
            'canAcknowledge' => $permissions->allows($request->user(), $enrollment->facility, FacilityPermissions::ACK_SHIFT_NOTES),
        ]);
    }

    public function store(Request $request, ClientEnrollment $enrollment, FacilityPermissions $permissions, OrganizationContext $organizations): RedirectResponse
    {
        $this->authorizeAssigned($request, $enrollment, $permissions, $organizations, FacilityPermissions::WRITE_SHIFT_NOTES);
        $data = $request->validate([
            'shift_date' => ['required', 'date', 'before_or_equal:today'],
            'shift' => ['required', Rule::in(['day', 'evening', 'overnight'])],
            'summary' => ['required', 'string', 'min:3', 'max:5000'],
            'observations' => ['nullable', 'string', 'max:5000'],
            'significant_events' => ['nullable', 'string', 'max:5000'],
            'interventions' => ['nullable', 'string', 'max:5000'],
            'handoff' => ['nullable', 'string', 'max:5000'],
        ]);
        $enrollment->shiftNotes()->create([...$data, 'author_id' => $request->user()->id]);
        return back()->with('success', 'End of Shift note recorded.');
    }

    public function acknowledge(Request $request, ClientEnrollment $enrollment, ClientShiftNote $note, FacilityPermissions $permissions, OrganizationContext $organizations): RedirectResponse
    {
        $this->authorizeAssigned($request, $enrollment, $permissions, $organizations, FacilityPermissions::ACK_SHIFT_NOTES);
        abort_unless((int) $note->client_enrollment_id === (int) $enrollment->id, 404);
        abort_if((int) $note->author_id === (int) $request->user()->id, 422, 'You cannot acknowledge your own note.');
        ClientShiftNoteAcknowledgment::query()->firstOrCreate(
            ['client_shift_note_id' => $note->id, 'user_id' => $request->user()->id],
            ['acknowledged_at' => now()]
        );
        return back()->with('success', 'Shift handoff acknowledged.');
    }
}
