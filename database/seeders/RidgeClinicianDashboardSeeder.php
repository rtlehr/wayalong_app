<?php

namespace Database\Seeders;

use App\Models\Person;
use App\Support\PersonNameSearch;
use Illuminate\Support\Facades\Crypt;
use App\Models\Facility;
use App\Models\User;
use App\Support\FacilityPermissions;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Fictional caseload records to exercise the My Clients dashboard, local/testing only. */
class RidgeClinicianDashboardSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new LogicException('The clinician dashboard demo may only run locally or in testing.');
        }

        $email = 'dev.ridge.clinician@wayalong.test';
        $clinician = DB::table('users')->where('email', $email)->where('is_active', true)->first();
        if (! $clinician) {
            throw new LogicException('Clinician account missing or inactive. Run DevelopmentSeeder first.');
        }

        $org = DB::table('organizations')->where('slug', 'blue-ridge-behavioral-health')->where('status', 'active')->first();
        if (! $org) {
            throw new LogicException('Blue Ridge sample organization missing. Run DevelopmentSeeder first.');
        }

        $membership = DB::table('organization_user')
            ->where('organization_id', $org->id)->where('user_id', $clinician->id)
            ->where('status', 'active')->first();
        if (! $membership) {
            throw new LogicException('The clinician has no active Blue Ridge membership.');
        }

        $facility = DB::table('facilities')->where('organization_id', $org->id)
            ->where('code', 'BR-TREAT')->where('status', 'active')->first();
        if (! $facility) {
            throw new LogicException('Blue Ridge Treatment Center not found. Run DevelopmentSeeder first.');
        }

        // Facility roles may be NULL: FacilityPermissions then inherits the active
        // organization membership role, just like the application's authorization.
        $effectiveRole = app(FacilityPermissions::class)->role(
            User::query()->findOrFail($clinician->id),
            // This seeder runs via Artisan without an authenticated session.
            // Facility is already verified by org/code/status above; avoid the
            // request-scoped Eloquent global scope for this lookup only.
            Facility::withoutGlobalScopes()
                ->where('organization_id', $org->id)
                ->where('status', 'active')
                ->findOrFail($facility->id),
        );
        if ($effectiveRole !== 'clinician') {
            throw new LogicException('The demo user must already hold the clinician role at Blue Ridge Treatment Center.');
        }

        // Unique, explicitly fictional identifiers; never modify unrelated clients.
        $cases = [
            ['UIUX DEMO Avery', 'Assigned Active', 'active', true, '555-0101'],
            ['UIUX DEMO Blake', 'Assigned Active', 'active', true, '555-0102'],
            ['UIUX DEMO Cameron', 'Assigned Active', 'active', true, '555-0103'],
            ['UIUX DEMO Drew', 'Not Assigned', 'active', false, '555-0104'],
            ['UIUX DEMO Ellis', 'Discharged', 'discharged', true, '555-0105'],
        ];

        DB::transaction(function () use ($cases, $org, $facility, $clinician): void {
            foreach ($cases as [$first, $last, $status, $assigned, $phone]) {
                $identity = [
                    'organization_id' => $org->id,
                    'person_type' => 'client',
                    'first_name' => $first,
                    'last_name' => $last,
                ];
                $personId = Person::withoutGlobalScopes()->where('organization_id', $org->id)
                    ->where('person_type', 'client')->get(['id', 'first_name', 'last_name'])
                    ->first(fn (Person $person) => $person->first_name === $first && $person->last_name === $last)?->id;
                if (! $personId) {
                    $personId = DB::table('people')->insertGetId(array_merge($identity, [
                        'first_name' => Crypt::encryptString($first),
                        'last_name' => Crypt::encryptString($last),
                        'facility_id' => $facility->id,
                        'date_of_birth' => Crypt::encryptString('2009-04-12'),
                        'notes' => Crypt::encryptString('Fictional My Clients dashboard test record.'),
                        'created_at' => now(), 'updated_at' => now(),
                    ]));
                    PersonNameSearch::sync((int) $personId, (int) $org->id, $first, $last);
                }

                $enrollmentKey = [
                    'organization_id' => $org->id,
                    'facility_id' => $facility->id,
                    'person_id' => $personId,
                ];
                $enrollmentId = DB::table('client_enrollments')->where($enrollmentKey)->value('id');
                if (! $enrollmentId) {
                    $enrollmentId = DB::table('client_enrollments')->insertGetId(array_merge($enrollmentKey, [
                        'status' => $status,
                        'enrolled_at' => today()->subDays(30)->toDateString(),
                        'discharged_at' => $status === 'discharged' ? today()->subDays(3)->toDateString() : null,
                        'created_at' => now(), 'updated_at' => now(),
                    ]));
                }

                // Do not overwrite statuses when rerunning after manual UI testing.
                if ($assigned) {
                    DB::table('client_staff_assignments')->insertOrIgnore([
                        'organization_id' => $org->id,
                        'client_enrollment_id' => $enrollmentId,
                        'user_id' => $clinician->id,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
                // Intentional unassigned case: no new assignment is inserted.
                DB::table('contact_methods')->updateOrInsert([
                    'contactable_type' => Person::class,
                    'contactable_id' => $personId,
                    'kind' => 'phone',
                    'label' => 'Dashboard Demo',
                ], [
                    'value' => Crypt::encryptString($phone),
                    'is_primary' => false,
                    'country' => 'US',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        $this->command?->info('My Clients demo seeded for '.$email.'.');
        $this->command?->info('Expected dashboard: Avery, Blake, Cameron (3 active assigned clients).');
        $this->command?->info('Drew is unassigned; Ellis is discharged. Both should be excluded.');
    }
}
