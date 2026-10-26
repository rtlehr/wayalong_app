<?php

namespace App\Http\Controllers;

use App\Models\ClientEnrollment;
use App\Models\ClientInsurancePolicy;
use App\Support\FacilityContext;
use App\Support\FacilityPermissions;
use App\Support\OrganizationContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ClientInsurancePolicyController extends Controller
{
    public function authorizedClient(Request $request, ClientEnrollment $enrollment, string $permission): int
    {
        $facility = app(FacilityContext::class)->current($request, app(OrganizationContext::class));
        abort_unless($facility && app(FacilityPermissions::class)->allows($request->user(), $facility, $permission), 403);
        // Facility-specific enrollment gives access to an organization-owned policy.
        abort_unless((int) $enrollment->facility_id === (int) $facility->id, 404);
        $person = $enrollment->person;
        abort_unless($person && $person->person_type === 'client' && (int) $person->organization_id === (int) $facility->organization_id, 404);

        return (int) $person->id;
    }

    private function input(Request $request): array
    {
        return $request->validate([
            'payer_name' => ['required', 'string', 'max:200'],
            'coverage_order' => ['required', Rule::in(['primary', 'secondary', 'tertiary'])],
            'member_id' => ['nullable', 'string', 'max:200'],
            'group_number' => ['nullable', 'string', 'max:200'],
            'plan_name' => ['nullable', 'string', 'max:200'],
            'subscriber_name' => ['nullable', 'string', 'max:200'],
            'subscriber_relationship' => ['nullable', Rule::in(['self', 'parent', 'guardian', 'spouse', 'other'])],
            'effective_on' => ['nullable', 'date'],
            'expires_on' => ['nullable', 'date', 'after_or_equal:effective_on'],
            'verification_status' => ['required', Rule::in(['unverified', 'pending', 'verified', 'rejected'])],
            'verified_on' => ['nullable', 'date', 'before_or_equal:today', 'required_if:verification_status,verified'],
            'notes' => ['nullable', 'string', 'max:3000'],
        ]);
    }

    public function store(Request $request, ClientEnrollment $enrollment): RedirectResponse
    {
        $personId = $this->authorizedClient($request, $enrollment, FacilityPermissions::MANAGE_CLIENTS);
        $data = $this->input($request);
        $providerId = $request->input('insurance_provider_id');
        $request->validate(['insurance_provider_id' => ['nullable', 'integer', Rule::exists('insurance_providers', 'id')->where('organization_id', $enrollment->organization_id)->where('is_active', true)]]);
        if ($providerId) {
            $provider = \App\Models\InsuranceProvider::findOrFail($providerId);
            $data['insurance_provider_id'] = $provider->id;
            $data['payer_name'] = $provider->name;
        } else {
            $data['insurance_provider_id'] = null;
        }
        $request->validate(['coverage_order' => [Rule::unique('client_insurance_policies')->where('organization_id', $enrollment->organization_id)->where('person_id', $personId)]]);
        ClientInsurancePolicy::create([...$data, 'person_id' => $personId]);

        return redirect()->route('facility.clients.edit', $enrollment)->with('success', 'Insurance policy added.');
    }

    public function update(Request $request, ClientEnrollment $enrollment, ClientInsurancePolicy $policy): RedirectResponse
    {
        $personId = $this->authorizedClient($request, $enrollment, FacilityPermissions::MANAGE_CLIENTS);
        abort_unless((int) $policy->person_id === $personId, 404);
        $data = $this->input($request);
        $providerId = $request->input('insurance_provider_id');
        $request->validate(['insurance_provider_id' => ['nullable', 'integer', Rule::exists('insurance_providers', 'id')->where('organization_id', $enrollment->organization_id)->where('is_active', true)]]);
        if ($providerId) {
            $provider = \App\Models\InsuranceProvider::findOrFail($providerId);
            $data['insurance_provider_id'] = $provider->id;
            $data['payer_name'] = $provider->name;
        } else {
            $data['insurance_provider_id'] = null;
        }
        $request->validate(['coverage_order' => [Rule::unique('client_insurance_policies')->where('organization_id', $enrollment->organization_id)->where('person_id', $personId)->ignore($policy->id)]]);
        $policy->update($data);

        return redirect()->route('facility.clients.edit', $enrollment)->with('success', 'Insurance policy updated.');
    }

    public function destroy(Request $request, ClientEnrollment $enrollment, ClientInsurancePolicy $policy): RedirectResponse
    {
        $personId = $this->authorizedClient($request, $enrollment, FacilityPermissions::MANAGE_CLIENTS);
        abort_unless((int) $policy->person_id === $personId, 404);
        if ($policy->verifications()->exists()) {
            return redirect()->route('facility.clients.edit', $enrollment)->withErrors(['insurance' => 'This policy has verification history and cannot be removed.']);
        }
        $policy->delete();

        return redirect()->route('facility.clients.edit', $enrollment)->with('success', 'Insurance policy removed.');
    }
}
