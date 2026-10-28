<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use LogicException;

/** Fictional facility leadership accounts for local role and dashboard testing only. */
class BlueRidgeFacilityAccountsSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new LogicException('Facility demo accounts may only be seeded in local or testing environments.');
        }

        $organization = DB::table('organizations')
            ->where('slug', 'blue-ridge-behavioral-health')
            ->where('status', 'active')
            ->first();

        if ($organization === null) {
            throw new LogicException('Blue Ridge Behavioral Health is missing or inactive. Run DevelopmentSeeder first.');
        }

        $accounts = [
            ['BR-TREAT', 'dev.ridge.treatment.owner@wayalong.test', 'BlueRidgeTreatment Owner', 'facility_owner', 'Facility Owner'],
            ['BR-TREAT', 'dev.ridge.treatment.admin@wayalong.test', 'BlueRidgeTreatment Admin', 'facility_admin', 'Facility Administrator'],
            ['NOVA', 'dev.ridge.nova.owner@wayalong.test', 'NorthernVirginiaCounseling Owner', 'facility_owner', 'Facility Owner'],
            ['NOVA', 'dev.ridge.nova.admin@wayalong.test', 'NorthernVirginiaCounseling Admin', 'facility_admin', 'Facility Administrator'],
        ];

        $facilities = [];
        foreach (['BR-TREAT', 'NOVA'] as $code) {
            $facility = DB::table('facilities')
                ->where('organization_id', $organization->id)
                ->where('code', $code)
                ->where('status', 'active')
                ->first();

            if ($facility === null) {
                throw new LogicException("Active Blue Ridge facility {$code} is missing. Run DevelopmentSeeder first.");
            }

            $facilities[$code] = $facility;
        }

        DB::transaction(function () use ($organization, $accounts, $facilities): void {
            foreach ($accounts as [$code, $email, $name, $facilityRole, $jobTitle]) {
                $facility = $facilities[$code];
                $user = User::query()->where('email', $email)->first();

                if ($user !== null) {
                    if ((int) $user->organization_id !== (int) $organization->id ||
                        $user->organizations()->where('organizations.id', '!=', $organization->id)->exists()) {
                        throw new LogicException("Account {$email} already belongs to a different organization. No changes made.");
                    }

                    $membership = DB::table('organization_user')->where('organization_id', $organization->id)
                        ->where('user_id', $user->id)->first();
                    if ($membership !== null && $membership->role !== 'staff') {
                        throw new LogicException("Account {$email} already holds a non-staff organization role. No changes made.");
                    }

                    $existingAssignments = DB::table('organization_staff_facilities')
                        ->where('organization_id', $organization->id)->where('user_id', $user->id)->get();
                    foreach ($existingAssignments as $assignment) {
                        if ((int) $assignment->facility_id !== (int) $facility->id ||
                            ($assignment->role !== null && $assignment->role !== $facilityRole)) {
                            throw new LogicException("Account {$email} already has another facility or role assignment. No changes made.");
                        }
                    }
                } else {
                    $user = User::query()->create([
                        'name' => $name,
                        'organization_id' => $organization->id,
                        'email' => $email,
                        'password' => Hash::make((string) env('WAYALONG_SEED_PASSWORD', 'WayAlong-Local-Only-2026!')),
                    ]);
                    $user->forceFill(['is_active' => true, 'email_verified_at' => now()])->save();
                }

                // Demo names are deterministic, but never change identities or credentials.
                $user->forceFill(['name' => $name])->save();

                DB::table('organization_user')->updateOrInsert(
                    ['organization_id' => $organization->id, 'user_id' => $user->id],
                    ['role' => 'staff', 'status' => 'active', 'updated_at' => now()],
                );
                DB::table('organization_staff_facilities')->updateOrInsert(
                    ['organization_id' => $organization->id, 'user_id' => $user->id, 'facility_id' => $facility->id],
                    ['role' => $facilityRole, 'updated_at' => now()],
                );
                DB::table('facility_staff_profiles')->updateOrInsert(
                    ['organization_id' => $organization->id, 'user_id' => $user->id, 'facility_id' => $facility->id],
                    ['job_title' => $jobTitle, 'staff_type' => 'Employee', 'updated_at' => now()],
                );
            }
        });

        $this->command?->info('Four Blue Ridge facility demo accounts are ready (one Owner and one Admin per facility).');
    }
}
