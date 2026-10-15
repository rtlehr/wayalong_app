<?php

namespace Database\Seeders;

use App\Models\Organization;

class DevelopmentSeeder extends WayAlongSampleSeeder
{
    public function run(): void
    {
        $this->assertSafeEnvironment();

        $valley = $this->organization('valley-youth-services', [
            'name' => 'Valley Youth Services', 'status' => 'active', 'timezone' => 'America/New_York',
        ]);
        $blueRidge = $this->organization('blue-ridge-behavioral-health', [
            'name' => 'Blue Ridge Behavioral Health', 'status' => 'active', 'timezone' => 'America/New_York',
        ]);
        $community = $this->organization('community-recovery-network', [
            'name' => 'Community Recovery Network', 'status' => 'inactive', 'timezone' => 'America/Chicago',
        ]);

        $this->addFacility($valley, 'WIN-RES', 'Winchester Residential Center', 'Winchester', 'VA');
        $this->addFacility($valley, 'SHEN-OUT', 'Shenandoah Outpatient Clinic', 'Front Royal', 'VA');
        $this->addFacility($blueRidge, 'BR-TREAT', 'Blue Ridge Treatment Center', 'Roanoke', 'VA');
        $this->addFacility($blueRidge, 'NOVA', 'Northern Virginia Counseling Center', 'Fairfax', 'VA');
        $this->addFacility($community, 'COMM', 'Community Recovery Campus', 'Richmond', 'VA');

        $owner = $this->user('dev.owner@wayalong.test', 'Morgan Carter');
        $admin = $this->user('dev.admin@wayalong.test', 'Taylor Morgan');
        $clinician = $this->user('dev.clinician@wayalong.test', 'Jordan Ellis');
        $staff = $this->user('dev.staff@wayalong.test', 'Avery Parker');
        $inactive = $this->user('dev.inactive@wayalong.test', 'Casey Reed');

        $this->membership($owner, $valley, 'owner');
        $this->membership($owner, $blueRidge, 'clinician'); // Tests multi-organization role changes.
        $this->membership($admin, $valley, 'admin');
        $this->membership($admin, $blueRidge, 'owner');
        $this->membership($clinician, $valley, 'clinician');
        $this->membership($staff, $valley, 'staff');
        $this->membership($inactive, $valley, 'staff', 'inactive');
        $this->membership($owner, $community, 'owner');
    }

    private function addFacility(Organization $organization, string $code, string $name, string $city, string $state): void
    {
        $this->facility($organization, $code, [
            'name' => $name, 'status' => 'active', 'address_line_1' => '100 Example Way',
            'city' => $city, 'state' => $state, 'postal_code' => '00000',
            'timezone' => 'America/New_York',
        ]);
    }
}
