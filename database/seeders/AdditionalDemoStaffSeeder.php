<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\AdditionalDemoStaffFixtures;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use LogicException;

/** Create six stable staff identities for local permission and lifecycle testing. */
final class AdditionalDemoStaffSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing']) || config('app.env') === 'production') {
            throw new LogicException('Demo staff seeding is restricted to local/testing environments.');
        }

        $definitions = AdditionalDemoStaffFixtures::accounts();
        $plan = [];
        // Preflight every target before inserting any identities.
        foreach ($definitions as $email => $fixture) {
            $organization = DB::table('organizations')->where('slug', $fixture['organization'])->first();
            if ($organization === null) {
                throw new LogicException("Missing organization {$fixture['organization']}; seed organizations first.");
            }
            $user = User::query()->where('email', $email)->first();
            if ($user && ($user->is_platform_admin || ($user->organization_id !== null && (int) $user->organization_id !== (int) $organization->id))) {
                throw new LogicException("Existing identity conflict: {$email}.");
            }
            $facilities = [];
            foreach ($fixture['facilities'] as $code => $role) {
                $facilityId = DB::table('facilities')->where('organization_id', $organization->id)->where('code', $code)->value('id');
                if ($facilityId === null) {
                    throw new LogicException("Missing facility {$code}; seed facilities first.");
                }
                $facilities[(int) $facilityId] = $role;
            }
            $plan[] = compact('email', 'fixture', 'organization', 'user', 'facilities');
        }

        DB::transaction(function () use ($plan): void {
            foreach ($plan as $row) {
                $user = $row['user'] ?? User::query()->create([
                    'name' => $row['fixture']['name'],
                    'email' => $row['email'],
                    'organization_id' => $row['organization']->id,
                    'password' => Hash::make((string) env('WAYALONG_SEED_PASSWORD', 'WayAlong-Local-Only-2026!')),
                ]);
                if ($row['user'] === null) {
                    $user->forceFill(['is_active' => true, 'email_verified_at' => now()])->save();
                }
                // Never overwrite existing identity details or credentials.
                DB::table('organization_user')->updateOrInsert(
                    ['organization_id' => $row['organization']->id, 'user_id' => $user->id],
                    ['role' => $row['fixture']['role'], 'status' => $row['fixture']['status'], 'updated_at' => now()],
                );
                foreach ($row['facilities'] as $facilityId => $role) {
                    DB::table('organization_staff_facilities')->updateOrInsert(
                        ['organization_id' => $row['organization']->id, 'user_id' => $user->id, 'facility_id' => $facilityId],
                        ['role' => $role, 'updated_at' => now()],
                    );
                }
            }
        });
        $this->command?->info('Six permanent demo staff identities seeded. Use wayalong:dev-reset-staff --apply to restore their assignments after testing.');
    }
}
