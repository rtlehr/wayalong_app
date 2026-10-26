<?php

namespace App\Http\Controllers;

use App\Models\InsuranceProvider;
use App\Support\OrganizationContext;
use App\Support\OrganizationPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class InsuranceProviderController extends Controller
{
    private function organization(Request $request, OrganizationContext $context, OrganizationPermissions $permissions): \App\Models\Organization
    {
        $organization = $context->current($request);
        abort_unless($organization && $permissions->allows($request->user(), $organization, OrganizationPermissions::MANAGE_ORGANIZATION), 403);

        return $organization;
    }

    public function index(Request $request, OrganizationContext $context, OrganizationPermissions $permissions): Response
    {
        $organization = $this->organization($request, $context, $permissions);

        return Inertia::render('organizations/InsuranceProviders', [
            'organization' => $organization->only('id', 'name'),
            'providers' => InsuranceProvider::query()->where('organization_id', $organization->id)->orderBy('name')->get(['id', 'name', 'payer_code', 'phone', 'is_active']),
        ]);
    }

    public function store(Request $request, OrganizationContext $context, OrganizationPermissions $permissions): RedirectResponse
    {
        $organization = $this->organization($request, $context, $permissions);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:200', Rule::unique('insurance_providers')->where('organization_id', $organization->id)],
            'payer_code' => ['nullable', 'string', 'max:80'],
            'phone' => ['nullable', 'string', 'max:40'],
        ]);
        InsuranceProvider::create($data);

        return redirect()->route('organization.admin.insurance-providers.index')->with('success', 'Insurance provider added.');
    }

    public function update(Request $request, InsuranceProvider $provider, OrganizationContext $context, OrganizationPermissions $permissions): RedirectResponse
    {
        $organization = $this->organization($request, $context, $permissions);
        abort_unless($provider->organization_id === $organization->id, 404);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:200', Rule::unique('insurance_providers')->where('organization_id', $organization->id)->ignore($provider->id)],
            'payer_code' => ['nullable', 'string', 'max:80'],
            'phone' => ['nullable', 'string', 'max:40'],
            'is_active' => ['required', 'boolean'],
        ]);
        $provider->update($data);

        return redirect()->route('organization.admin.insurance-providers.index')->with('success', 'Insurance provider updated.');
    }
}
