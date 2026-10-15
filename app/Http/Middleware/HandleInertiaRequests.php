<?php

namespace App\Http\Middleware;

use App\Support\OrganizationContext;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
            ],
            'organizationContext' => fn () => $this->organizationContext($request),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
    /** @return array{active: array<string, mixed>|null, available: list<array<string, mixed>>} */
    private function organizationContext(Request $request): array
    {
        if (! $request->user()) {
            return ['active' => null, 'available' => []];
        }

        $active = app(OrganizationContext::class)->current($request);
        $available = $request->user()->organizations()
            ->where('organizations.status', 'active')
            ->wherePivot('status', 'active')
            ->orderBy('organizations.name')
            ->get(['organizations.id', 'organizations.name', 'organizations.slug'])
            ->map(fn ($organization) => [
                'id' => $organization->id,
                'name' => $organization->name,
                'slug' => $organization->slug,
                'role' => $organization->pivot->role,
            ])->all();

        return [
            'active' => $active === null ? null : [
                'id' => $active->id,
                'name' => $active->name,
                'slug' => $active->slug,
            ],
            'available' => $available,
        ];
    }
}
