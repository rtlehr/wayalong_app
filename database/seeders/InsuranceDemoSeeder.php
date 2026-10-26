<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Person;
use App\Support\PersonNameSearch;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Fictional, repeatable local-only insurance walkthrough data. Never run in production. */
class InsuranceDemoSeeder extends Seeder
{
    private int $org;
    private int $facility;
    private int $owner;

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Insurance demo data is strictly limited to local/testing environments.');
        }

        // Prefer the existing Blue Ridge sample organization, but accept any active
        // organization that has an owner assigned to an active facility.
        $site = DB::table('organizations as o')
            ->join('organization_user as ou', 'ou.organization_id', '=', 'o.id')
            ->join('users as u', 'u.id', '=', 'ou.user_id')
            ->join('organization_staff_facilities as osf', function ($j) {
                $j->on('osf.organization_id', '=', 'o.id')->on('osf.user_id', '=', 'u.id');
            })
            ->join('facilities as f', function ($j) {
                $j->on('f.id', '=', 'osf.facility_id')->on('f.organization_id', '=', 'o.id');
            })
            ->where('o.status', 'active')->where('ou.status', 'active')
            ->where('ou.role', 'owner')->where('u.is_active', true)
            ->where('f.status', 'active')
            ->orderByRaw("CASE WHEN o.slug = 'blue-ridge-behavioral-health' THEN 0 ELSE 1 END")
            ->orderBy('o.id')->orderBy('f.id')
            ->select(['o.id as organization_id', 'o.name as organization_name', 'o.slug', 'f.id as facility_id', 'f.name as facility_name', 'u.id as owner_id', 'u.email'])
            ->first();

        if (! $site) {
            throw new RuntimeException('No active organization owner assigned to an active facility. Configure that first, then rerun InsuranceDemoSeeder.');
        }

        $this->org = (int) $site->organization_id;
        $this->facility = (int) $site->facility_id;
        $this->owner = (int) $site->owner_id;

        DB::transaction(function (): void {
            $provider = $this->record('insurance_providers', ['organization_id' => $this->org, 'name' => 'DEMO Sample Health Plan'], [
                'payer_code' => 'DEMO-ONLY', 'phone' => '555-0100', 'is_active' => 1,
            ]);

            $scenarios = [
                ['Reconciled', 'Fully Matched', 50000, 'approved', 40000, 30000, 10000, 30000, 10000, 10000, 'verified', 240],
                ['Mismatch', 'Payment Review', 80000, 'approved', 70000, 50000, 20000, 35000, 5000, 10000, 'verified', 240],
                ['Pending', 'Claim In Process', 35000, 'pending', null, null, null, 0, 0, 0, 'pending', 240],
                ['Coverage', 'Expiring Soon', 22500, 'draft', null, null, null, 0, 0, 0, 'verified', 8],
                ['Reversed', 'Payment Correction', 20000, 'approved', 15000, 10000, 5000, 10000, 5000, 5000, 'verified', 240],
            ];

            foreach ($scenarios as [$first, $last, $chargeAmount, $status, $allowed, $reportedInsurance, $patient, $paidInsurance, $paidClient, $adjustment, $verification, $expiryDays]) {
                $person = Person::withoutGlobalScopes()->where('organization_id', $this->org)
                    ->where('person_type', 'client')->get(['id', 'first_name', 'last_name'])
                    ->first(fn (Person $candidate) => $candidate->first_name === 'DEMO '.$first && $candidate->last_name === $last)?->id;
                if (! $person) {
                    $person = DB::table('people')->insertGetId([
                        'organization_id' => $this->org,
                        'first_name' => Crypt::encryptString('DEMO '.$first),
                        'last_name' => Crypt::encryptString($last),
                        'person_type' => 'client', 'facility_id' => $this->facility,
                        'date_of_birth' => Crypt::encryptString('2009-04-12'),
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    PersonNameSearch::sync((int) $person, $this->org, 'DEMO '.$first, $last);
                }
                $this->record('client_enrollments', [
                    'organization_id' => $this->org, 'facility_id' => $this->facility, 'person_id' => $person,
                ], ['status' => 'active', 'enrolled_at' => now()->subMonths(2)->toDateString()]);
                $policy = $this->record('client_insurance_policies', [
                    'organization_id' => $this->org, 'person_id' => $person, 'coverage_order' => 'primary',
                ], [
                    'insurance_provider_id' => $provider, 'payer_name' => 'DEMO Sample Health Plan',
                    'member_id' => Crypt::encryptString('DEMO-'.strtoupper($first).'-001'),
                    'group_number' => Crypt::encryptString('DEMO-GROUP-01'),
                    'plan_name' => 'Fictional demonstration policy', 'subscriber_name' => 'Demo Guardian',
                    'subscriber_relationship' => 'parent', 'effective_on' => now()->subYear()->toDateString(),
                    'expires_on' => now()->addDays($expiryDays)->toDateString(),
                    'verification_status' => $verification,
                    'verified_on' => $verification === 'verified' ? today()->toDateString() : null,
                ]);
                $this->record('insurance_verifications', [
                    'organization_id' => $this->org, 'client_insurance_policy_id' => $policy,
                ], [
                    'reference_number' => Crypt::encryptString('DEMO-VERIFICATION-'.$first),
                    'verified_by' => $this->owner, 'status' => $verification, 'method' => 'portal',
                    'checked_on' => today()->toDateString(), 'notes' => Crypt::encryptString('Fictional training verification'),
                ]);
                $charge = $this->record('client_billing_charges', [
                    'organization_id' => $this->org, 'person_id' => $person, 'description' => 'DEMO Insurance Services - '.$first,
                ], [
                    'facility_id' => $this->facility, 'created_by' => $this->owner,
                    'service_on' => today()->subDays(12)->toDateString(), 'amount_cents' => $chargeAmount,
                    'responsibility' => 'insurance', 'notes' => Crypt::encryptString('Training-only charge'),
                ]);
                $claim = $this->record('client_insurance_claims', [
                    'organization_id' => $this->org, 'charge_id' => $charge, 'claim_number' => 'DEMO-CLAIM-'.$first,
                ], [
                    'person_id' => $person, 'facility_id' => $this->facility, 'created_by' => $this->owner,
                    'client_insurance_policy_id' => $policy, 'status' => $status,
                    'submitted_on' => $status === 'draft' ? null : today()->subDays(10)->toDateString(),
                    'adjudicated_on' => $allowed === null ? null : today()->subDays(3)->toDateString(),
                    'allowed_amount_cents' => $allowed, 'insurer_paid_cents' => $reportedInsurance,
                    'patient_responsibility_cents' => $patient,
                    'notes' => Crypt::encryptString('Training-only claim'),
                ]);
                foreach ($this->statusTrail($status) as $step) {
                    $this->record('client_insurance_claim_events', [
                        'organization_id' => $this->org, 'claim_id' => $claim, 'status' => $step,
                    ], ['created_by' => $this->owner, 'notes' => Crypt::encryptString('DEMO status: '.$step)]);
                }
                if ($paidInsurance > 0) {
                    $this->payment($person, $charge, 'insurance', $paidInsurance, 'DEMO-PAY-'.$first.'-INS');
                }
                if ($paidClient > 0) {
                    $clientPayment = $this->payment($person, $charge, 'client', $paidClient, 'DEMO-PAY-'.$first.'-CLIENT');
                    if ($first === 'Reversed') {
                        $this->record('client_billing_payments', [
                            'organization_id' => $this->org, 'reverses_payment_id' => $clientPayment,
                        ], [
                            'person_id' => $person, 'facility_id' => $this->facility, 'charge_id' => $charge,
                            'created_by' => $this->owner, 'received_on' => today()->toDateString(),
                            'payer_type' => 'client', 'method' => 'check', 'amount_cents' => -$paidClient,
                            'notes' => Crypt::encryptString('DEMO correction: duplicate entry'),
                        ]);
                    }
                }
                if ($adjustment > 0) {
                    $this->record('client_claim_adjustments', [
                        'organization_id' => $this->org, 'claim_id' => $claim, 'reverses_adjustment_id' => null,
                    ], [
                        'person_id' => $person, 'facility_id' => $this->facility, 'charge_id' => $charge,
                        'created_by' => $this->owner, 'adjusted_on' => today()->subDays(2)->toDateString(),
                        'kind' => 'contractual', 'amount_cents' => $adjustment,
                        'reason' => Crypt::encryptString('DEMO contractual adjustment'),
                    ]);
                }
            }
        });

        $this->command?->info("Insurance demo ready: {$site->organization_name} / {$site->facility_name}; sign in as {$site->email}. Five fictional clients prefixed DEMO were created or reused.");
    }

    /** @param array<string,mixed> $key @param array<string,mixed> $values @param list<string>|null $matchColumns */
    private function record(string $table, array $key, array $values, ?array $matchColumns = null): int
    {
        $query = DB::table($table);
        foreach ($matchColumns ?? array_keys($key) as $column) {
            $query->where($column, $key[$column]);
        }
        $existing = $query->value('id');
        if ($existing !== null) {
            return (int) $existing;
        }

        return (int) DB::table($table)->insertGetId(array_merge($key, $values, [
            'created_at' => now(), 'updated_at' => now(),
        ]));
    }

    private function payment(int $person, int $charge, string $payer, int $amount, string $reference): int
    {
        return $this->record('client_billing_payments', [
            'organization_id' => $this->org, 'charge_id' => $charge, 'payer_type' => $payer,
        ], [
            'person_id' => $person, 'facility_id' => $this->facility, 'created_by' => $this->owner,
            'received_on' => today()->subDays(2)->toDateString(),
            'reference' => Crypt::encryptString($reference), 'method' => $payer === 'insurance' ? 'insurer_remittance' : 'check',
            'amount_cents' => $amount, 'notes' => Crypt::encryptString('Fictional training payment'),
        ], ['organization_id', 'charge_id', 'payer_type']);
    }

    /** @return list<string> */
    private function statusTrail(string $status): array
    {
        return match ($status) {
            'draft' => ['draft'],
            'pending' => ['draft', 'submitted', 'pending'],
            'approved' => ['draft', 'submitted', 'approved'],
            'denied' => ['draft', 'submitted', 'denied'],
            default => ['draft', 'submitted', 'approved', 'closed'],
        };
    }
}
