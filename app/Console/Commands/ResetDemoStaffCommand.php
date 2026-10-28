<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Support\AdditionalDemoStaffFixtures;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** Restore only the documented fictional staff fixtures; never reseed an entire database. */
final class ResetDemoStaffCommand extends Command
{
    protected $signature = 'wayalong:dev-reset-staff {--apply : Apply the previewed changes}';

    protected $description = 'Preview or restore allowlisted development staff roles and facility assignments (local/testing only)';

    /** @var array<string, array{organization:string, role:string, status:string, facilities:array<string,string>}> */
    private const ACCOUNTS = [
        'dev.valley.owner@wayalong.test' => ['organization' => 'valley-youth-services', 'role' => 'owner', 'status' => 'active', 'facilities' => ['WIN-RES' => 'staff', 'SHEN-OUT' => 'staff']],
        'dev.valley.admin@wayalong.test' => ['organization' => 'valley-youth-services', 'role' => 'admin', 'status' => 'active', 'facilities' => ['WIN-RES' => 'staff', 'SHEN-OUT' => 'staff']],
        'dev.valley.clinician@wayalong.test' => ['organization' => 'valley-youth-services', 'role' => 'clinician', 'status' => 'active', 'facilities' => ['WIN-RES' => 'clinician', 'SHEN-OUT' => 'clinician']],
        'dev.valley.staff@wayalong.test' => ['organization' => 'valley-youth-services', 'role' => 'staff', 'status' => 'active', 'facilities' => ['WIN-RES' => 'staff']],
        'dev.valley.inactive@wayalong.test' => ['organization' => 'valley-youth-services', 'role' => 'staff', 'status' => 'inactive', 'facilities' => ['WIN-RES' => 'staff']],
        'dev.ridge.owner@wayalong.test' => ['organization' => 'blue-ridge-behavioral-health', 'role' => 'owner', 'status' => 'active', 'facilities' => ['BR-TREAT' => 'staff', 'NOVA' => 'staff']],
        'dev.ridge.clinician@wayalong.test' => ['organization' => 'blue-ridge-behavioral-health', 'role' => 'clinician', 'status' => 'active', 'facilities' => ['BR-TREAT' => 'clinician']],
        'dev.ridge.treatment.owner@wayalong.test' => ['organization' => 'blue-ridge-behavioral-health', 'role' => 'staff', 'status' => 'active', 'facilities' => ['BR-TREAT' => 'facility_owner']],
        'dev.ridge.treatment.admin@wayalong.test' => ['organization' => 'blue-ridge-behavioral-health', 'role' => 'staff', 'status' => 'active', 'facilities' => ['BR-TREAT' => 'facility_admin']],
        'dev.ridge.nova.owner@wayalong.test' => ['organization' => 'blue-ridge-behavioral-health', 'role' => 'staff', 'status' => 'active', 'facilities' => ['NOVA' => 'facility_owner']],
        'dev.ridge.nova.admin@wayalong.test' => ['organization' => 'blue-ridge-behavioral-health', 'role' => 'staff', 'status' => 'active', 'facilities' => ['NOVA' => 'facility_admin']],
        'dev.community.owner@wayalong.test' => ['organization' => 'community-recovery-network', 'role' => 'owner', 'status' => 'active', 'facilities' => []],
    ];

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing']) || config('app.env') === 'production') {
            $this->error('Refused: this command is only available in local/testing environments.');
            return self::FAILURE;
        }

        try {
            $plan = $this->plan();
        } catch (Throwable $exception) {
            $this->error('No changes made: '.$exception->getMessage());
            return self::FAILURE;
        }

        $this->info(($this->option('apply') ? 'Applying' : 'Previewing').' known demo account assignments:');
        foreach ($plan as $row) {
            $this->line($row['email'].': '.$row['role'].' / '.$row['status'].'; '.count($row['facilities']).' fixture facility assignment(s)');
        }
        $this->warn('Original demo users retain unrelated assignments. Six additional test identities reset to exact assigned/unassigned facility state. Individual permission overrides are preserved.');

        if (! $this->option('apply')) {
            $this->comment('Preview only. Use --apply to make changes. Existing accounts only; missing demo accounts are skipped.');
            return self::SUCCESS;
        }

        DB::transaction(function () use ($plan): void {
            foreach ($plan as $row) {
                $membership = ['organization_id' => $row['organization_id'], 'user_id' => $row['user_id']];
                DB::table('organization_user')->updateOrInsert($membership, [
                    'role' => $row['role'], 'status' => $row['status'], 'updated_at' => now(),
                ]);
                // These six dedicated testing identities reset to an exact facility roster,
                // including zero assignments for intentionally unassigned staff.
                if (isset(AdditionalDemoStaffFixtures::accounts()[$row['email']])) {
                    $existing = DB::table('organization_staff_facilities')
                        ->where($membership)->get(['id', 'facility_id']);
                    foreach ($existing as $assignment) {
                        if (array_key_exists((int) $assignment->facility_id, $row['facilities'])) {
                            continue;
                        }
                        DB::table('facility_assignment_roles')->where('assignment_id', $assignment->id)->delete();
                        DB::table('facility_staff_profiles')->where($membership)->where('facility_id', $assignment->facility_id)->delete();
                        DB::table('organization_staff_facilities')->where('id', $assignment->id)->delete();
                    }
                }
                foreach ($row['facilities'] as $facilityId => $role) {
                    $key = $membership + ['facility_id' => (int) $facilityId];
                    DB::table('organization_staff_facilities')->updateOrInsert($key, ['role' => $role, 'updated_at' => now()]);
                    $assignmentId = DB::table('organization_staff_facilities')->where($key)->value('id');
                    // Reset additive roles only on the fixture assignments, not other facilities.
                    DB::table('facility_assignment_roles')->where('assignment_id', $assignmentId)->delete();
                }
            }
        });
        $this->info('Development staff fixtures restored. Passwords, identity activation, and unrelated memberships were untouched.');
        return self::SUCCESS;
    }

    /** @return list<array{email:string,user_id:int,organization_id:int,role:string,status:string,facilities:array<int,string>}> */
    private function plan(): array
    {
        $plan = [];
        foreach (array_merge(self::ACCOUNTS, AdditionalDemoStaffFixtures::accounts()) as $email => $definition) {
            $user = DB::table('users')->where('email', $email)->first();
            if ($user === null) {
                $this->warn("Skipping missing fixture account {$email}.");
                continue;
            }
            if ($user->is_platform_admin || ! str_ends_with($email, '@wayalong.test')) {
                throw new RuntimeException("Unexpected identity for {$email}.");
            }
            $organization = DB::table('organizations')->where('slug', $definition['organization'])->first();
            if ($organization === null) {
                throw new RuntimeException("Organization missing for {$email}.");
            }
            if ($user->organization_id !== null && (int) $user->organization_id !== (int) $organization->id) {
                throw new RuntimeException("Identity ownership mismatch for {$email}.");
            }
            // One central identity may legitimately belong to multiple organizations.
            // Only the allowlisted organization's membership and facilities are reset.
            $facilities = [];
            foreach ($definition['facilities'] as $code => $role) {
                $facilityId = DB::table('facilities')->where('organization_id', $organization->id)->where('code', $code)->value('id');
                if ($facilityId === null) {
                    throw new RuntimeException("Facility {$code} missing for {$email}.");
                }
                $facilities[(int) $facilityId] = $role;
            }
            $plan[] = ['email' => $email, 'user_id' => (int) $user->id,
                'organization_id' => (int) $organization->id, 'role' => $definition['role'],
                'status' => $definition['status'], 'facilities' => $facilities];
        }
        return $plan;
    }
}
