<?php

namespace Database\Seeders;

class QATestSeeder extends WayAlongSampleSeeder
{
    public function run(): void
    {
        $this->assertSafeEnvironment();
        $this->platformAdministrator('qa.platform.admin@wayalong.test', 'WayAlong Platform Administrator');
        $alpha = $this->organization('qa-alpha', ['name' => 'QA Alpha Treatment', 'status' => 'active', 'timezone' => 'America/New_York']);
        $beta = $this->organization('qa-beta', ['name' => 'QA Beta Treatment', 'status' => 'active', 'timezone' => 'America/Chicago']);
        $inactive = $this->organization('qa-inactive', ['name' => 'QA Inactive Organization', 'status' => 'inactive', 'timezone' => 'America/New_York']);

        $main = $this->facility($alpha, 'MAIN', ['name' => 'QA Alpha Main', 'status' => 'active']);
        $annex = $this->facility($alpha, 'SECOND', ['name' => 'QA Alpha Annex', 'status' => 'inactive']);
        $betaMain = $this->facility($beta, 'MAIN', ['name' => 'QA Beta Main', 'status' => 'active']);
        $this->facility($inactive, 'MAIN', ['name' => 'QA Inactive Main', 'status' => 'active']);

        $ownerA = $this->organizationUser($alpha, 'qa.alpha.owner@wayalong.test', 'QA Owner', 'owner');
        $adminA = $this->organizationUser($alpha, 'qa.alpha.admin@wayalong.test', 'QA Admin', 'admin');
        $clinicianA = $this->organizationUser($alpha, 'qa.alpha.clinician@wayalong.test', 'QA Clinician', 'clinician');
        $staffA = $this->organizationUser($alpha, 'qa.alpha.staff@wayalong.test', 'QA Staff', 'staff');
        $disabledA = $this->organizationUser($alpha, 'qa.alpha.disabled@wayalong.test', 'QA Disabled Staff', 'staff', 'inactive', false);
        $ownerB = $this->organizationUser($beta, 'qa.beta.owner@wayalong.test', 'QA Owner', 'owner');
        $clinicianB = $this->organizationUser($beta, 'qa.beta.clinician@wayalong.test', 'QA Clinician', 'clinician');
        $this->organizationUser($inactive, 'qa.inactive.owner@wayalong.test', 'QA Inactive Owner', 'owner');

        $this->assignFacilities($alpha, $ownerA, [$main, $annex], 'Owner');
        $this->assignFacilities($alpha, $adminA, [$main], 'Administrator');
        $this->assignFacilities($alpha, $clinicianA, [$main, $annex], 'Clinician');
        $this->assignFacilities($alpha, $staffA, [$main], 'Staff');
        $this->assignFacilities($alpha, $disabledA, [$main], 'Inactive Staff');
        $this->assignFacilities($beta, $ownerB, [$betaMain], 'Owner');
        $this->assignFacilities($beta, $clinicianB, [$betaMain], 'Clinician');
        $this->contact($clinicianA, 'phone', 'Mobile', '555-0301', true);
        $this->contact($clinicianA, 'phone', 'Work', '555-0302');
        $this->contact($clinicianB, 'phone', 'Mobile', '555-0401', true);
    }
}
