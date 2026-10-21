<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use LogicException;

/** Deterministic fictional sample data. Restricted to local and testing environments. */
abstract class WayAlongSampleSeeder extends Seeder
{
    final protected function assertSafeEnvironment(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new LogicException('Sample data may only be seeded in local or testing environments.');
        }
    }

    /** @param array<string, mixed> $attributes */
    final protected function organization(string $slug, array $attributes): Organization
    {
        return Organization::query()->updateOrCreate(['slug' => $slug], $attributes);
    }

    /** @param array<string, mixed> $attributes */
    final protected function facility(Organization $organization, string $code, array $attributes): int
    {
        $key = ['organization_id' => $organization->id, 'code' => $code];
        DB::table('facilities')->updateOrInsert($key, array_merge($attributes, ['updated_at' => now()]));

        return (int) DB::table('facilities')->where($key)->value('id');
    }

    /**
     * Until the account ownership migration lands, every seeded account uses a
     * distinct login email; this method refuses to reuse it across organizations.
     */
    final protected function organizationUser(
        Organization $organization,
        string $email,
        string $name,
        string $role,
        string $membershipStatus = 'active',
        bool $accountActive = true,
    ): User {
        if (! str_ends_with($email, '@wayalong.test')) {
            throw new LogicException('Sample accounts require the reserved @wayalong.test domain.');
        }

        $existing = User::query()->where('email', $email)->first();
        if ($existing !== null && $existing->organizations()->where('organizations.id', '!=', $organization->id)->exists()) {
            throw new LogicException("Sample login {$email} is already associated with another organization.");
        }

        $user = $existing ?? User::query()->create([
            'name' => $name,
            'organization_id' => $organization->id,
            'email' => $email,
            'password' => Hash::make((string) env('WAYALONG_SEED_PASSWORD', 'WayAlong-Local-Only-2026!')),
        ]);

        if ($user->organization_id !== null && (int) $user->organization_id !== $organization->id) {
            throw new LogicException('Seed account ownership conflicts with the requested organization.');
        }
        $user->forceFill(['organization_id' => $organization->id, 'name' => $name, 'is_active' => $accountActive, 'email_verified_at' => $user->email_verified_at ?? now()])->save();
        $user->organizations()->syncWithoutDetaching([
            $organization->id => ['role' => $role, 'status' => $membershipStatus],
        ]);
        $user->organizations()->updateExistingPivot($organization->id, [
            'role' => $role, 'status' => $membershipStatus,
        ]);

        return $user;
    }

    final protected function platformAdministrator(string $email, string $name): User
    {
        if (! str_ends_with($email, '@wayalong.test')) {
            throw new LogicException('Sample platform administrators require a reserved email address.');
        }

        $user = User::query()->firstOrCreate(['email' => $email], [
            'name' => $name,
            'password' => Hash::make((string) env('WAYALONG_SEED_PASSWORD', 'WayAlong-Local-Only-2026!')),
            'email_verified_at' => now(),
        ]);
        if ($user->organizations()->exists()) {
            throw new LogicException('Sample platform administrator account must not have organization memberships.');
        }
        $user->forceFill(['name' => $name, 'is_active' => true, 'is_platform_admin' => true])->save();

        return $user;
    }

    /** @param list<int> $facilityIds */
    final protected function assignFacilities(Organization $organization, User $user, array $facilityIds, string $jobTitle = 'Staff Member'): void
    {
        foreach (array_unique($facilityIds) as $facilityId) {
            if (! DB::table('facilities')->where('id', $facilityId)->where('organization_id', $organization->id)->exists()) {
                throw new LogicException('Cannot seed an assignment to a facility in another organization.');
            }
            $key = ['organization_id' => $organization->id, 'user_id' => $user->id, 'facility_id' => $facilityId];
            DB::table('organization_staff_facilities')->updateOrInsert($key, ['updated_at' => now()]);
            DB::table('facility_staff_profiles')->updateOrInsert($key, ['job_title' => $jobTitle, 'staff_type' => 'Employee', 'updated_at' => now()]);
        }
    }

    final protected function contact(User $user, string $kind, string $label, string $value, bool $primary = false): void
    {
        $key = ['contactable_type' => User::class, 'contactable_id' => $user->id, 'kind' => $kind, 'label' => $label];
        DB::table('contact_methods')->updateOrInsert($key, [
            'value' => $value, 'is_primary' => $primary, 'country' => 'US', 'updated_at' => now(),
        ]);
    }
}
