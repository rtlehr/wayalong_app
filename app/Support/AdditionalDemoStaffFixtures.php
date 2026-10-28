<?php

namespace App\Support;

/** Additional, permanently defined local/test-only staff identities. */
final class AdditionalDemoStaffFixtures
{
    /** @return array<string, array{name:string,organization:string,role:string,status:string,facilities:array<string,string>}> */
    public static function accounts(): array
    {
        return [
            'dev.ridge.demo.casey@wayalong.test' => ['name' => 'Casey Morgan (Demo Clinician)', 'organization' => 'blue-ridge-behavioral-health', 'role' => 'clinician', 'status' => 'active', 'facilities' => ['BR-TREAT' => 'clinician']],
            'dev.ridge.demo.jamie@wayalong.test' => ['name' => 'Jamie Carter (Demo Staff)', 'organization' => 'blue-ridge-behavioral-health', 'role' => 'staff', 'status' => 'active', 'facilities' => ['NOVA' => 'staff']],
            'dev.valley.demo.taylor@wayalong.test' => ['name' => 'Taylor Reed (Demo Clinician)', 'organization' => 'valley-youth-services', 'role' => 'clinician', 'status' => 'active', 'facilities' => ['WIN-RES' => 'clinician']],
            'dev.valley.demo.alex@wayalong.test' => ['name' => 'Alex Parker (Demo Staff)', 'organization' => 'valley-youth-services', 'role' => 'staff', 'status' => 'active', 'facilities' => ['SHEN-OUT' => 'staff']],
            'dev.ridge.demo.jordan@wayalong.test' => ['name' => 'Jordan Ellis (Demo Unassigned)', 'organization' => 'blue-ridge-behavioral-health', 'role' => 'staff', 'status' => 'active', 'facilities' => []],
            'dev.valley.demo.riley@wayalong.test' => ['name' => 'Riley Brooks (Demo Unassigned)', 'organization' => 'valley-youth-services', 'role' => 'staff', 'status' => 'active', 'facilities' => []],
        ];
    }
}
