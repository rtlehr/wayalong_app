<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');


// Explicit CLI-only bootstrap. Never grant platform privileges through organization forms.
Artisan::command('wayalong:grant-platform-admin {email}', function (string $email): void {
    $user = \App\Models\User::query()->where('email', $email)->first();
    if ($user === null) {
        $this->error('No existing user matches that email.');
        return;
    }
    if (! $this->confirm('Grant this user platform-wide organization administration access?')) {
        return;
    }
    $user->forceFill(['is_platform_admin' => true])->save();
    $this->info('Platform administration access granted.');
})->purpose('Grant platform administration to an existing user (CLI only)');

/**
 * Read-only prerequisite check for the organization-owned user migration.
 * Do not split accounts until every ambiguous membership has been reviewed.
 */
Artisan::command('wayalong:audit-user-ownership', function (): int {
    $membershipCounts = \Illuminate\Support\Facades\DB::table('organization_user')
        ->select('user_id')
        ->selectRaw('COUNT(DISTINCT organization_id) as organization_count')
        ->groupBy('user_id');

    $sharedUsers = \Illuminate\Support\Facades\DB::query()
        ->fromSub($membershipCounts, 'memberships')
        ->where('organization_count', '>', 1)
        ->count();
    $organizationless = \Illuminate\Support\Facades\DB::table('users as u')
        ->leftJoin('organization_user as ou', 'ou.user_id', '=', 'u.id')
        ->whereNull('ou.user_id')
        ->where('u.is_platform_admin', false)
        ->count();
    $mixedPlatformUsers = \Illuminate\Support\Facades\DB::table('users as u')
        ->join('organization_user as ou', 'ou.user_id', '=', 'u.id')
        ->where('u.is_platform_admin', true)
        ->distinct()
        ->count('u.id');

    $this->table(['Ownership condition', 'Accounts'], [
        ['Users belonging to multiple organizations', $sharedUsers],
        ['Non-platform users with no organization', $organizationless],
        ['Platform admins with organization memberships', $mixedPlatformUsers],
    ]);

    if ($sharedUsers > 0 || $organizationless > 0 || $mixedPlatformUsers > 0) {
        $this->warn('Account ownership needs review before the organization-owned user migration. No records were changed.');

        return self::FAILURE;
    }

    $this->info('No ambiguous user ownership found. This is a prerequisite check, not a full data-migration validation.');

    return self::SUCCESS;
})->purpose('Audit existing accounts before organization-owned user migration (read-only)');

// Schedule this once daily through Laravel's scheduler (php artisan schedule:run).
Artisan::command('wayalong:documents-check-expiration', function (): void {
    $count = app(\App\Support\DocumentExpirationNotifier::class)->send();
    $this->info("Document expiration notifications sent: {$count}");
})->purpose('Notify authorized facility staff of expiring or expired client documents');

\Illuminate\Support\Facades\Schedule::command('wayalong:documents-check-expiration')->dailyAt('08:00')->withoutOverlapping();
