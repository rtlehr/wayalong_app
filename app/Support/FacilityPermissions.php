<?php

namespace App\Support;

use App\Models\Facility;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Authorization for facility-owned records. An organization-level role alone
 * never grants a clinical permission in an unrelated facility.
 */
final class FacilityPermissions
{
    public const VIEW_STAFF = 'facility.staff.view';
    public const VIEW_CLIENTS = 'facility.clients.view';
    public const VIEW_CLINICAL_CASELOAD = 'facility.clients.clinical_caseload.view';
    public const MANAGE_CLIENTS = 'facility.clients.manage';
    public const MANAGE_ALERTS = 'facility.alerts.manage';
    public const VIEW_SHIFT_NOTES = 'facility.shift_notes.view';
    public const WRITE_SHIFT_NOTES = 'facility.shift_notes.write';
    public const ACK_SHIFT_NOTES = 'facility.shift_notes.acknowledge';

    /** @var array<string, list<string>> */
    private const ROLE_PERMISSIONS = [
        'clinician' => [self::VIEW_STAFF, self::VIEW_CLIENTS, self::VIEW_CLINICAL_CASELOAD, self::MANAGE_ALERTS, self::VIEW_SHIFT_NOTES, self::WRITE_SHIFT_NOTES, self::ACK_SHIFT_NOTES],
        'client_admin' => [self::VIEW_STAFF, self::VIEW_CLIENTS, self::MANAGE_CLIENTS],
        'floor_staff' => [self::VIEW_CLIENTS, self::VIEW_CLINICAL_CASELOAD, self::VIEW_SHIFT_NOTES, self::WRITE_SHIFT_NOTES, self::ACK_SHIFT_NOTES],
        'staff' => [self::VIEW_STAFF],
    ];

    public function role(?User $user, Facility $facility): ?string
    {
        if ($user === null || $facility->status !== 'active') {
            return null;
        }

        $membership = $user->organizations()
            ->whereKey($facility->organization_id)
            ->where('organizations.status', 'active')
            ->wherePivot('status', 'active')
            ->first();

        if ($membership === null) {
            return null;
        }

        $assignment = DB::table('organization_staff_facilities')
            ->where('organization_id', $facility->organization_id)
            ->where('facility_id', $facility->id)
            ->where('user_id', $user->id)
            ->first(['role']);

        if ($assignment === null) {
            return null;
        }

        // Older assignments have no facility role. They inherit their organization's
        // role until an explicit facility-specific role is assigned.
        return $assignment->role ?: $membership->pivot->role;
    }

    public function allows(?User $user, Facility $facility, string $permission): bool
    {
        $role = $this->role($user, $facility);
        if ($role === null) {
            return false;
        }

        // An active, explicitly facility-assigned owner/admin can manage client
        // administration. This does not grant alert, shift-note, or other
        // treatment permissions without a separate clinical assignment.
        if (in_array($role, ['owner', 'admin'], true)) {
            return in_array($permission, [self::VIEW_STAFF, self::VIEW_CLIENTS, self::MANAGE_CLIENTS], true);
        }

        return in_array($permission, self::ROLE_PERMISSIONS[$role] ?? [], true);
    }
}
