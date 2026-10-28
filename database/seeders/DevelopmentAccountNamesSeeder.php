<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Rename only existing fictional @wayalong.test logins for easy local role testing. */
class DevelopmentAccountNamesSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new LogicException('Development account names may only be updated locally or in tests.');
        }

        // This is intentionally a fixed allowlist: never rename regular or production users.
        $names = [
            'dev.platform.admin@wayalong.test' => 'WayAlong PlatformAdmin',
            'dev.valley.owner@wayalong.test' => 'ValleyYouthServices Owner',
            'dev.valley.admin@wayalong.test' => 'ValleyYouthServices Admin',
            'dev.valley.clinician@wayalong.test' => 'ValleyYouthServices Clinician',
            'dev.valley.staff@wayalong.test' => 'ValleyYouthServices Staff',
            'dev.valley.inactive@wayalong.test' => 'ValleyYouthServices InactiveStaff',
            'dev.ridge.owner@wayalong.test' => 'BlueRidge Owner',
            'dev.ridge.clinician@wayalong.test' => 'BlueRidgeTreatment Clinician',
            'dev.ridge.treatment.owner@wayalong.test' => 'BlueRidgeTreatment Owner',
            'dev.ridge.treatment.admin@wayalong.test' => 'BlueRidgeTreatment Admin',
            'dev.ridge.nova.owner@wayalong.test' => 'NorthernVirginiaCounseling Owner',
            'dev.ridge.nova.admin@wayalong.test' => 'NorthernVirginiaCounseling Admin',
            'dev.community.owner@wayalong.test' => 'CommunityRecoveryNetwork Owner',
        ];

        $updated = 0;
        foreach ($names as $email => $name) {
            // Query builder avoids mutating roles, user passwords, active flags, etc.
            $updated += DB::table('users')->where('email', $email)->update(['name' => $name]);
        }

        $this->command?->info("Updated {$updated} existing development login display names. No roles or passwords changed.");
    }
}
