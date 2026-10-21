<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Support\OrganizationContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Features;

/**
 * Transitional organization login entry point. The existing Fortify login
 * pipeline (including two-factor challenges) continues to handle credentials.
 */
class OrganizationLoginController extends Controller
{
    public const LOGIN_ORGANIZATION_KEY = 'login_organization_id';

    public function __invoke(Request $request, Organization $organization): Response|\Illuminate\Http\RedirectResponse
    {
        abort_unless($organization->status === 'active', 404);

        if ($request->user()) {
            // Never switch the organization for an authenticated account by URL.
            $allowed = $request->user()->organizations()->whereKey($organization->id)
                ->wherePivot('status', 'active')->exists();
            abort_unless($allowed, 403);
            $request->session()->put(OrganizationContext::SESSION_KEY, $organization->id);
            return redirect()->route('dashboard');
        }

        $request->session()->put(self::LOGIN_ORGANIZATION_KEY, $organization->id);

        return Inertia::render('auth/Login', [
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
            'status' => $request->session()->get('status'),
            'organizationName' => $organization->name,
        ]);
    }
}
