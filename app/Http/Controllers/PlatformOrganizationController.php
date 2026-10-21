<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class PlatformOrganizationController extends Controller
{
    private function authorizePlatform(Request $request): void
    {
        abort_unless($request->user()?->is_platform_admin === true, 403, 'Platform administration required.');
    }

    public function index(Request $request): Response
    {
        $this->authorizePlatform($request);
        $search = trim((string) $request->query('search', ''));
        $organizations = Organization::query()
            ->when($search !== '', fn ($q) => $q->where(fn ($nested) => $nested->where('name', 'like', '%'.$search.'%')->orWhere('slug', 'like', '%'.$search.'%')))
            ->withCount('users')
            ->orderBy('name')->paginate(15)->withQueryString();

        // Facility models use a tenant global scope. Platform reporting must count
        // facilities across tenants without granting the platform admin record access.
        $facilityCounts = DB::table('facilities')
            ->select('organization_id', DB::raw('COUNT(*) as aggregate'))
            ->whereIn('organization_id', $organizations->getCollection()->pluck('id'))
            ->groupBy('organization_id')
            ->pluck('aggregate', 'organization_id');

        $organizations->getCollection()->each(function (Organization $organization) use ($facilityCounts): void {
            $organization->setAttribute('facilities_count', (int) ($facilityCounts[$organization->id] ?? 0));
        });

        return Inertia::render('platform/Organizations', [
            'organizations' => $organizations,
            'search' => $search,
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorizePlatform($request);
        return Inertia::render('platform/OrganizationForm', ['mode' => 'create']);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizePlatform($request);
        $data = $this->validated($request);
        $organization = Organization::create($data);
        return to_route('platform.organizations.edit', $organization)->with('success', 'Organization created successfully.');
    }

    public function show(Request $request, Organization $organization): Response
    {
        $this->authorizePlatform($request);
        return Inertia::render('platform/OrganizationForm', ['mode' => 'view', 'organization' => $organization->only('id', 'name', 'slug', 'status', 'timezone')]);
    }

    public function edit(Request $request, Organization $organization): Response
    {
        $this->authorizePlatform($request);
        return Inertia::render('platform/OrganizationForm', ['mode' => 'edit', 'organization' => $organization->only('id', 'name', 'slug', 'status', 'timezone')]);
    }

    public function update(Request $request, Organization $organization): RedirectResponse
    {
        $this->authorizePlatform($request);
        $organization->update($this->validated($request, $organization));
        return back()->with('success', 'Organization updated successfully.');
    }

    public function destroy(Request $request, Organization $organization): RedirectResponse
    {
        $this->authorizePlatform($request);
        DB::transaction(function () use ($organization): void {
            $locked = Organization::query()->lockForUpdate()->findOrFail($organization->id);
            $counts = [
                'facilities' => $locked->facilities()->count(),
                'users' => $locked->users()->count(),
                'roles' => $locked->customRoles()->count(),
                'invitations' => DB::table('organization_invitations')->where('organization_id', $locked->id)->count(),
            ];
            foreach (['organization_staff_details', 'organization_staff_facilities', 'facility_staff_profiles'] as $table) {
                if (\Illuminate\Support\Facades\Schema::hasTable($table)) {
                    $counts[$table] = DB::table($table)->where('organization_id', $locked->id)->count();
                }
            }
            if (array_sum($counts) > 0) {
                throw ValidationException::withMessages(['organization' => 'This organization has related records and cannot be deleted. Remove all facilities, users, roles, invitations, and staff assignments first, or set it to inactive.']);
            }
            $locked->delete();
        });
        return to_route('platform.organizations.index')->with('success', 'Organization deleted successfully.');
    }

    private function validated(Request $request, ?Organization $organization = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'alpha_dash:ascii', 'max:255', Rule::unique('organizations', 'slug')->ignore($organization?->id)],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'timezone' => ['required', 'timezone'],
        ]);
    }
}
