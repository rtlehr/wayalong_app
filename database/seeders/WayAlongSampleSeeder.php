<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use LogicException;

/** Shared, deterministic sample data. Never use with live accounts or patient data. */
abstract class WayAlongSampleSeeder extends Seeder
{
    final protected function assertSafeEnvironment(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new LogicException('WayAlong sample data can only be seeded in local or testing environments.');
        }
    }

    /** @param array<string, mixed> $attributes */
    final protected function organization(string $slug, array $attributes): Organization
    {
        return Organization::query()->updateOrCreate(['slug' => $slug], $attributes);
    }

    /** @param array<string, mixed> $attributes */
    final protected function facility(Organization $organization, string $code, array $attributes): void
    {
        // Facility has a tenant global scope. Seed by the composite organization/code key.
        $key = ['organization_id' => $organization->id, 'code' => $code];
        $query = DB::table('facilities')->where($key);

        if ($query->exists()) {
            $query->update(array_merge($attributes, ['updated_at' => now()]));
        } else {
            DB::table('facilities')->insert(array_merge($key, $attributes, [
                'created_at' => now(), 'updated_at' => now(),
            ]));
        }
    }

    final protected function user(string $email, string $name): User
    {
        if (! str_ends_with($email, '@wayalong.test')) {
            throw new LogicException('Sample accounts must use the reserved @wayalong.test domain.');
        }

        // Do not reset a previously changed password when the seed is re-run.
        $user = User::query()->firstOrCreate(['email' => $email], [
            'name' => $name,
            'password' => Hash::make((string) env('WAYALONG_SEED_PASSWORD', 'WayAlong-Local-Only-2026!')),
            'email_verified_at' => now(),
        ]);

        return $user;
    }

    final protected function membership(User $user, Organization $organization, string $role, string $status = 'active'): void
    {
        $user->organizations()->syncWithoutDetaching([
            $organization->id => ['role' => $role, 'status' => $status],
        ]);
        $user->organizations()->updateExistingPivot($organization->id, ['role' => $role, 'status' => $status]);
    }
}
