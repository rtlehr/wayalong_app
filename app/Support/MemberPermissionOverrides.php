<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/** Organization and facility scoped explicit decisions; membership and assignment remain mandatory. */
final class MemberPermissionOverrides
{
    public function decision(int $organizationId, int $userId, string $permission, ?int $facilityId): ?bool
    {
        $query = DB::table('member_permission_overrides')
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->where('permission', $permission);

        if ($facilityId === null) {
            $query->whereNull('facility_id');
        } else {
            $query->where('facility_id', $facilityId);
        }

        $decision = $query->value('decision');

        return $decision === 'allow' ? true : ($decision === 'deny' ? false : null);
    }

    public function saved(int $organizationId, int $userId, ?int $facilityId): array
    {
        $query = DB::table('member_permission_overrides')
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId);
        $facilityId === null ? $query->whereNull('facility_id') : $query->where('facility_id', $facilityId);

        return $query->pluck('decision', 'permission')->all();
    }

    public function save(int $organizationId, int $userId, ?int $facilityId, int $actorId, string $permission, string $decision): void
    {
        DB::transaction(function () use ($organizationId, $userId, $facilityId, $actorId, $permission, $decision): void {
            $scope = DB::table('member_permission_overrides')
                ->where('organization_id', $organizationId)
                ->where('user_id', $userId)
                ->where('permission', $permission);
            $facilityId === null ? $scope->whereNull('facility_id') : $scope->where('facility_id', $facilityId);
            $existing = $scope->lockForUpdate()->first();
            $before = $existing?->decision;
            $after = $decision === 'inherit' ? null : $decision;
            if ($before === $after) {
                return;
            }
            if ($existing !== null) {
                if ($after === null) {
                    DB::table('member_permission_overrides')->where('id', $existing->id)->delete();
                } else {
                    DB::table('member_permission_overrides')->where('id', $existing->id)
                        ->update(['decision' => $after, 'updated_at' => now()]);
                }
            } elseif ($after !== null) {
                DB::table('member_permission_overrides')->insert([
                    'organization_id' => $organizationId, 'user_id' => $userId,
                    'facility_id' => $facilityId, 'scope_key' => $facilityId === null ? 'organization' : 'facility:'.$facilityId, 'permission' => $permission,
                    'decision' => $after, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            DB::table('member_permission_override_events')->insert([
                'organization_id' => $organizationId, 'user_id' => $userId,
                'facility_id' => $facilityId, 'actor_id' => $actorId,
                'permission' => $permission, 'old_decision' => $before,
                'new_decision' => $decision, 'created_at' => now(),
            ]);
        });
    }
}
