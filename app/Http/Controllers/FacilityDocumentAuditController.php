<?php

namespace App\Http\Controllers;

use App\Support\DocumentManager;
use App\Support\FacilityContext;
use App\Support\FacilityPermissions;
use App\Support\OrganizationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Read-only, facility-scoped administrative overview of client documents. */
final class FacilityDocumentAuditController extends Controller
{
    public function __invoke(Request $request, OrganizationContext $organizations, FacilityContext $facilities, FacilityPermissions $permissions): Response
    {
        $facility = $facilities->current($request, $organizations);
        abort_unless($facility !== null && $permissions->allows($request->user(), $facility, FacilityPermissions::MANAGE_CLIENTS), 403);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'expiration' => ['nullable', Rule::in(['all', 'expired', 'upcoming', 'current'])],
        ]);
        $search = trim($filters['search'] ?? '');
        $expiration = $filters['expiration'] ?? 'all';

        // Require a real attachment to an enrollment in this exact facility.
        // Document metadata alone is not sufficient evidence of record access.
        $documents = DB::table('documents as d')
            ->join('document_attachments as a', function ($join): void {
                $join->on('a.document_id', '=', 'd.id')->where('a.record_type', '=', DocumentManager::CLIENT_ENROLLMENT);
            })
            ->join('client_enrollments as e', function ($join): void {
                $join->on('e.id', '=', 'a.record_id')->on('e.organization_id', '=', 'd.organization_id')->on('e.facility_id', '=', 'd.facility_id');
            })
            ->where('d.organization_id', $facility->organization_id)
            ->where('d.facility_id', $facility->id)
            ->where('e.organization_id', $facility->organization_id)
            ->where('e.facility_id', $facility->id)
            ->when($search !== '', fn ($query) => $query->where(function ($q) use ($search): void {
                $q->where('d.title', 'like', '%'.$search.'%')->orWhere('d.category', 'like', '%'.$search.'%');
            }))
            ->when($expiration === 'expired', fn ($query) => $query->whereDate('d.expires_on', '<', today()))
            ->when($expiration === 'upcoming', fn ($query) => $query->whereBetween('d.expires_on', [today()->toDateString(), today()->addDays(30)->toDateString()]))
            ->when($expiration === 'current', fn ($query) => $query->where(fn ($q) => $q->whereNull('d.expires_on')->orWhereDate('d.expires_on', '>', today()->addDays(30))))
            ->select('d.id', 'd.title', 'd.category', 'd.original_filename', 'd.review_status', 'd.expires_on', 'd.created_at', 'e.id as enrollment_id')
            ->orderByDesc('d.id')
            ->paginate(20, ['*'], 'documents_page')->withQueryString();

        $events = DB::table('document_access_events as events')
            ->join('documents as d', 'd.id', '=', 'events.document_id')
            ->join('document_attachments as a', function ($join): void {
                $join->on('a.document_id', '=', 'd.id')
                    ->on('a.record_id', '=', 'events.record_id')
                    ->where('a.record_type', '=', DocumentManager::CLIENT_ENROLLMENT);
            })
            ->join('client_enrollments as e', function ($join): void {
                $join->on('e.id', '=', 'a.record_id')->on('e.organization_id', '=', 'd.organization_id')->on('e.facility_id', '=', 'd.facility_id');
            })
            ->join('users as users', 'users.id', '=', 'events.user_id')
            ->where('events.record_type', DocumentManager::CLIENT_ENROLLMENT)
            ->where('e.organization_id', $facility->organization_id)
            ->where('e.facility_id', $facility->id)
            ->where('d.organization_id', $facility->organization_id)
            ->where('d.facility_id', $facility->id)
            ->select('events.id', 'events.action', 'events.created_at', 'd.title as document_title', 'users.name as actor', 'e.id as enrollment_id')
            ->orderByDesc('events.id')
            ->paginate(20, ['*'], 'events_page')->withQueryString();

        return Inertia::render('facilities/DocumentAudit', [
            'facility' => $facility->only('id', 'name'),
            'documents' => $documents,
            'events' => $events,
            'filters' => ['search' => $search, 'expiration' => $expiration],
        ]);
    }
}
