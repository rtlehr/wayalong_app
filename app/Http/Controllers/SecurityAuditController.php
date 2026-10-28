<?php

namespace App\Http\Controllers;

use App\Support\OrganizationContext;
use App\Support\OrganizationPermissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Read-only, organization-scoped metadata; never exposes clinical note content. */
final class SecurityAuditController extends Controller
{
    public function __invoke(Request $request, OrganizationContext $context, OrganizationPermissions $permissions): Response
    {
        $organization = $context->current($request);
        abort_unless($organization !== null && $permissions->allows($request->user(), $organization, OrganizationPermissions::VIEW_SECURITY_AUDIT), 403);

        $filters = $request->validate([
            'user_id' => ['nullable', 'integer', 'min:1'],
            'client_enrollment_id' => ['nullable', 'integer', 'min:1'],
            'facility_id' => ['nullable', 'integer', 'min:1'],
            'section' => ['nullable', Rule::in(['overview', 'alerts', 'shift_notes', 'admin_profile', 'document_download', 'directory_export'])],
            'outcome' => ['nullable', Rule::in(['viewed', 'denied', 'exported'])],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $events = DB::table('clinical_access_events as event')
            ->leftJoin('client_enrollments as enrollment', function ($join): void {
                $join->on('enrollment.id', '=', 'event.client_enrollment_id')
                    ->on('enrollment.organization_id', '=', 'event.organization_id');
            })
            ->leftJoin('users as actor', 'actor.id', '=', 'event.user_id')
            ->where('event.organization_id', $organization->id)
            ->when(isset($filters['user_id']), fn ($query) => $query->where('event.user_id', $filters['user_id']))
            ->when(isset($filters['client_enrollment_id']), fn ($query) => $query->where('event.client_enrollment_id', $filters['client_enrollment_id']))
            ->when(isset($filters['facility_id']), fn ($query) => $query->where('enrollment.facility_id', $filters['facility_id']))
            ->when(isset($filters['section']), fn ($query) => $query->where('event.section', $filters['section']))
            ->when(isset($filters['outcome']), fn ($query) => $query->where('event.outcome', $filters['outcome']))
            ->when(isset($filters['from']), fn ($query) => $query->whereDate('event.created_at', '>=', $filters['from']))
            ->when(isset($filters['to']), fn ($query) => $query->whereDate('event.created_at', '<=', $filters['to']))
            ->select('event.id', 'event.user_id', 'actor.name as staff_name', 'event.client_enrollment_id',
                'enrollment.facility_id', 'event.section', 'event.outcome', 'event.created_at')
            ->orderByDesc('event.id')
            ->paginate(25)->withQueryString();

        return Inertia::render('admin/SecurityAudit', [
            'organization' => $organization->only('id', 'name'),
            'events' => $events,
            'filters' => $filters,
        ]);
    }
}
