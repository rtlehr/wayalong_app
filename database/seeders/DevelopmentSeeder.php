<?php

namespace Database\Seeders;

use App\Models\Organization;

class DevelopmentSeeder extends WayAlongSampleSeeder
{
    public function run(): void
    {
        $this->assertSafeEnvironment();
        $this->platformAdministrator('dev.platform.admin@wayalong.test', 'WayAlong PlatformAdmin');

        $valley = $this->organization('valley-youth-services', ['name' => 'Valley Youth Services', 'status' => 'active', 'timezone' => 'America/New_York']);
        $ridge = $this->organization('blue-ridge-behavioral-health', ['name' => 'Blue Ridge Behavioral Health', 'status' => 'active', 'timezone' => 'America/New_York']);
        $community = $this->organization('community-recovery-network', ['name' => 'Community Recovery Network', 'status' => 'inactive', 'timezone' => 'America/Chicago']);

        $valleyMain = $this->sampleFacility($valley, 'WIN-RES', 'Winchester Residential Center', 'Winchester');
        $valleyOutpatient = $this->sampleFacility($valley, 'SHEN-OUT', 'Shenandoah Outpatient Clinic', 'Front Royal');
        $ridgeMain = $this->sampleFacility($ridge, 'BR-TREAT', 'Blue Ridge Treatment Center', 'Roanoke');
        $ridgeNorth = $this->sampleFacility($ridge, 'NOVA', 'Northern Virginia Counseling Center', 'Fairfax');
        $this->sampleFacility($community, 'COMM', 'Community Recovery Campus', 'Richmond');

        $ownerA = $this->organizationUser($valley, 'dev.valley.owner@wayalong.test', 'ValleyYouthServices Owner', 'owner');
        $adminA = $this->organizationUser($valley, 'dev.valley.admin@wayalong.test', 'ValleyYouthServices Admin', 'admin');
        $clinicianA = $this->organizationUser($valley, 'dev.valley.clinician@wayalong.test', 'ValleyYouthServices Clinician', 'clinician');
        $staffA = $this->organizationUser($valley, 'dev.valley.staff@wayalong.test', 'ValleyYouthServices Staff', 'staff');
        $inactiveA = $this->organizationUser($valley, 'dev.valley.inactive@wayalong.test', 'ValleyYouthServices InactiveStaff', 'staff', 'inactive', false);
        $ownerB = $this->organizationUser($ridge, 'dev.ridge.owner@wayalong.test', 'BlueRidge Owner', 'owner');
        $clinicianB = $this->organizationUser($ridge, 'dev.ridge.clinician@wayalong.test', 'BlueRidgeTreatment Clinician', 'clinician');
        $this->organizationUser($community, 'dev.community.owner@wayalong.test', 'CommunityRecoveryNetwork Owner', 'owner');

        $this->assignFacilities($valley, $ownerA, [$valleyMain, $valleyOutpatient], 'Executive Director');
        $this->assignFacilities($valley, $adminA, [$valleyMain, $valleyOutpatient], 'Facility Administrator');
        $this->assignFacilities($valley, $clinicianA, [$valleyMain, $valleyOutpatient], 'Clinician');
        $this->assignFacilities($valley, $staffA, [$valleyMain], 'Support Specialist');
        $this->assignFacilities($valley, $inactiveA, [$valleyMain], 'Former Staff');
        $this->assignFacilities($ridge, $ownerB, [$ridgeMain, $ridgeNorth], 'Executive Director');
        $this->assignFacilities($ridge, $clinicianB, [$ridgeMain], 'Clinician');

        $this->contact($clinicianA, 'phone', 'Mobile', '555-0101', true);
        $this->contact($clinicianA, 'phone', 'Work', '555-0102');
        $this->contact($clinicianA, 'email', 'Alternate', 'jordan.personal@example.test', true);
        $this->contact($clinicianB, 'phone', 'Mobile', '555-0201', true);
    }

    private function sampleFacility(Organization $organization, string $code, string $name, string $city): int
    {
        return $this->facility($organization, $code, [
            'name' => $name, 'status' => 'active', 'address_line_1' => '100 Example Way',
            'city' => $city, 'state' => 'VA', 'postal_code' => '00000', 'timezone' => 'America/New_York',
        ]);
    }
}
