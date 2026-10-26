<?php

namespace App\Http\Controllers;

use App\Models\ClientEnrollment;
use App\Models\ClientInsurancePolicy;
use App\Models\InsuranceVerification;
use App\Support\FacilityPermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class InsuranceVerificationController extends Controller
{
    public function store(Request $request, ClientEnrollment $enrollment, ClientInsurancePolicy $policy, ClientInsurancePolicyController $access): RedirectResponse
    {
        $personId = $access->authorizedClient($request, $enrollment, FacilityPermissions::MANAGE_CLIENTS);
        abort_unless((int) $policy->person_id === $personId, 404);
        $data = $request->validate([
            'status' => ['required', Rule::in(['pending', 'verified', 'rejected', 'unverified'])],
            'method' => ['required', Rule::in(['phone', 'portal', 'fax', 'email', 'other'])],
            'checked_on' => ['required', 'date', 'before_or_equal:today'],
            'reference_number' => ['nullable', 'string', 'max:200'],
            'notes' => ['nullable', 'string', 'max:3000'],
        ]);
        DB::transaction(function () use ($request, $policy, $data): void {
            InsuranceVerification::create([...$data, 'client_insurance_policy_id' => $policy->id, 'verified_by' => $request->user()->id]);
            $policy->update(['verification_status' => $data['status'], 'verified_on' => $data['status'] === 'verified' ? $data['checked_on'] : null]);
        });

        return redirect()->route('facility.clients.edit', $enrollment)->with('success', 'Verification recorded.');
    }
}
