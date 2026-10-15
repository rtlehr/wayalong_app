<?php

namespace App\Models\Concerns;

use App\Models\Organization;
use App\Models\User;
use App\Support\OrganizationContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Apply to every model that stores organization-owned information.
 * Background jobs must explicitly establish an authorized organization context;
 * queries without one return no rows rather than exposing all organizations.
 */
trait BelongsToOrganization
{
    protected static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope('organization', function (Builder $query): void {
            $organizationId = static::activeOrganizationId();

            if ($organizationId === null) {
                $query->whereRaw('1 = 0');

                return;
            }

            $query->where($query->getModel()->qualifyColumn('organization_id'), $organizationId);
        });

        static::creating(function (Model $model): void {
            $organizationId = static::activeOrganizationId();

            if ($organizationId === null || ($model->organization_id !== null && (int) $model->organization_id !== $organizationId)) {
                throw new AuthorizationException('Cannot create records outside the active organization.');
            }

            $model->organization_id = $organizationId;
        });

        static::saving(function (Model $model): void {
            $organizationId = static::activeOrganizationId();

            // Laravel fires "saving" before "creating". A new record may
            // legitimately have no organization_id yet: the creating hook
            // assigns it. Never allow a supplied ID from another organization.
            if ($organizationId === null ||
                ($model->organization_id !== null && (int) $model->organization_id !== $organizationId) ||
                ($model->exists && ($model->organization_id === null || $model->isDirty('organization_id')))) {
                throw new AuthorizationException('Cannot modify records outside the active organization.');
            }
        });

        static::deleting(function (Model $model): void {
            if (static::activeOrganizationId() === null || (int) $model->organization_id !== static::activeOrganizationId()) {
                throw new AuthorizationException('Cannot delete records outside the active organization.');
            }
        });
    }

    protected static function activeOrganizationId(): ?int
    {
        // Eloquent can run outside an HTTP controller (including during tests).
        // Resolve the authenticated principal and session through Laravel's
        // services rather than requiring request()->hasSession().
        $user = auth()->user();

        if (! $user instanceof User) {
            return null;
        }

        $memberships = $user->organizations()
            ->where('organizations.status', 'active')
            ->wherePivot('status', 'active');

        $selected = session()->get(OrganizationContext::SESSION_KEY);

        if ($selected !== null) {
            $organization = (clone $memberships)->whereKey($selected)->first();

            if ($organization !== null) {
                return (int) $organization->id;
            }

            session()->forget(OrganizationContext::SESSION_KEY);
        }

        $organization = $memberships->orderBy('organizations.id')->first();

        if ($organization === null) {
            return null;
        }

        session()->put(OrganizationContext::SESSION_KEY, $organization->id);

        return (int) $organization->id;
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
