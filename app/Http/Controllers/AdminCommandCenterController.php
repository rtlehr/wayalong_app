<?php

namespace App\Http\Controllers;

use App\Models\OrganizationInvitation;
use App\Support\AdminToolCatalog;
use App\Support\OrganizationContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class AdminCommandCenterController extends Controller
{
    public function __invoke(Request $request, OrganizationContext $context, AdminToolCatalog $catalog): Response
    {
        $organization = $context->current($request);
        abort_unless($organization !== null, 403);

        $tools = $catalog->forUser($request->user(), $organization);
        abort_if($tools === [], 403, 'No administrative tools available for this organization.');

        return Inertia::render('admin/CommandCenter', [
            'organization' => $organization->only('id', 'name'),
            'canManagePlatform' => (bool) $request->user()->is_platform_admin,
            'tools' => $tools,
            'stats' => [
                'facilities' => $organization->facilities()->count(),
                'activeFacilities' => $organization->facilities()->where('status', 'active')->count(),
                'staff' => $organization->users()->count(),
                'activeStaff' => $organization->users()->wherePivot('status', 'active')->count(),
                'customRoles' => $organization->customRoles()->count(),
                'pendingInvitations' => OrganizationInvitation::query()
                    ->where('organization_id', $organization->id)
                    ->whereNull('accepted_at')->where('expires_at', '>', now())->count(),
            ],
        ]);
    }
}
