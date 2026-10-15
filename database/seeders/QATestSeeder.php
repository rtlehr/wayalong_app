<?php

namespace Database\Seeders;

class QATestSeeder extends WayAlongSampleSeeder
{
    public function run(): void
    {
        $this->assertSafeEnvironment();

        $alpha = $this->organization('qa-alpha', ['name' => 'QA Alpha Treatment', 'status' => 'active', 'timezone' => 'America/New_York']);
        $beta = $this->organization('qa-beta', ['name' => 'QA Beta Treatment', 'status' => 'active', 'timezone' => 'America/Chicago']);
        $inactive = $this->organization('qa-inactive', ['name' => 'QA Inactive Organization', 'status' => 'inactive', 'timezone' => 'America/New_York']);

        $this->facility($alpha, 'MAIN', ['name' => 'QA Alpha Main', 'status' => 'active']);
        $this->facility($alpha, 'SECOND', ['name' => 'QA Alpha Annex', 'status' => 'inactive']);
        $this->facility($beta, 'MAIN', ['name' => 'QA Beta Main', 'status' => 'active']);
        $this->facility($inactive, 'MAIN', ['name' => 'QA Inactive Main', 'status' => 'active']);

        $owner = $this->user('qa.owner@wayalong.test', 'QA Owner');
        $admin = $this->user('qa.admin@wayalong.test', 'QA Admin');
        $clinician = $this->user('qa.clinician@wayalong.test', 'QA Clinician');
        $staff = $this->user('qa.staff@wayalong.test', 'QA Staff');
        $disabled = $this->user('qa.disabled@wayalong.test', 'QA Disabled Staff');
        $outsider = $this->user('qa.outsider@wayalong.test', 'QA No Membership');

        $this->membership($owner, $alpha, 'owner');
        $this->membership($owner, $beta, 'staff');
        $this->membership($owner, $inactive, 'owner');
        $this->membership($admin, $alpha, 'admin');
        $this->membership($admin, $beta, 'owner');
        $this->membership($clinician, $alpha, 'clinician');
        $this->membership($staff, $alpha, 'staff');
        $this->membership($disabled, $alpha, 'staff', 'inactive');
        // $outsider intentionally has no memberships.
    }
}
