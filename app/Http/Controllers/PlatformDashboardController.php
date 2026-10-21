<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class PlatformDashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        abort_unless($request->user()?->is_platform_admin === true, 403, 'Platform administration required.');

        return Inertia::render('platform/Dashboard', [
            'stats' => [
                'organizations' => Organization::query()->count(),
                'activeOrganizations' => Organization::query()->where('status', 'active')->count(),
                'inactiveOrganizations' => Organization::query()->where('status', 'inactive')->count(),
                // Facility models are tenant-scoped. Global platform totals use a read-only aggregate.
                'facilities' => DB::table('facilities')->count(),
            ],
            'organizations' => Organization::query()
                ->orderBy('name')
                ->get(['id', 'name', 'slug', 'status']),
        ]);
    }
}
