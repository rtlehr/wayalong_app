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

/**
 * Safe, read-only baseline check. Does not print configuration secrets.
 * Infrastructure encryption, key custody, BAAs, and HIPAA risk assessment
 * must be verified separately.
 */
Artisan::command('wayalong:security-check', function (): int {
    $checks = [
        ['Application is in production mode', app()->environment('production')],
        ['Debug mode disabled', config('app.debug') === false],
        ['Application URL uses HTTPS', str_starts_with((string) config('app.url'), 'https://')],
        ['Session payload encryption enabled', config('session.encrypt') === true],
        ['Session cookies require HTTPS', config('session.secure') === true],
        ['Session cookies are HTTP-only', config('session.http_only') === true],
        ['Session cookies use SameSite Lax or Strict', in_array(config('session.same_site'), ['lax', 'strict'], true)],
        ['Application key is configured', is_string(config('app.key')) && strlen((string) config('app.key')) > 15],
        ['Logging is not at debug level', ! in_array(config('logging.channels.'.config('logging.default').'.level'), ['debug'], true)
            && ! in_array(config('logging.channels.single.level'), ['debug'], true)],
        ['Production mailer does not write messages to logs', ! in_array(config('mail.default'), ['log', 'array'], true)],
    ];

    $this->table(['Security prerequisite', 'Result'], array_map(
        fn (array $check): array => [$check[0], $check[1] ? 'PASS' : 'FAIL'],
        $checks
    ));

    $failed = count(array_filter($checks, fn (array $check): bool => ! $check[1]));
    if ($failed > 0) {
        $this->error("{$failed} security prerequisite(s) failed. Do not deploy with real patient data.");
        return self::FAILURE;
    }

    $this->info('Application configuration prerequisites passed. Infrastructure and organizational HIPAA controls still require verification.');
    return self::SUCCESS;
})->purpose('Check production security prerequisites without exposing secrets');

/**
 * Run only after backing up both the database and the private file disk,
 * with uploads/replacements paused. Defaults to read-only inventory.
 */
Artisan::command('wayalong:encrypt-legacy-documents {--execute : Write encrypted copies and switch metadata} {--backup-confirmed : Confirm verified database and file backups and exclusive document access}', function (): int {
    $execute = (bool) $this->option('execute');
    if ($execute && ! $this->option('backup-confirmed')) {
        $this->error('Execution requires --backup-confirmed and exclusive document access.');
        return self::FAILURE;
    }

    try {
        $result = app(\App\Support\LegacyDocumentEncryptor::class)->run($execute);
        if ($execute) {
            $this->info("Converted {$result['converted']} legacy document file(s). Original files retained for verified recovery.");
        } else {
            $this->info("Dry run: {$result['pending']} legacy document file(s) need conversion. No files or metadata changed.");
        }
        return self::SUCCESS;
    } catch (\Throwable $exception) {
        // Do not print private document paths or data to logs/terminal.
        $this->error('Legacy conversion stopped due to a storage or metadata validation problem. Review files and backups securely.');
        return self::FAILURE;
    }
})->purpose('Review or securely convert older client documents; retains originals for recovery');

\Illuminate\Support\Facades\Artisan::command('wayalong:audit-retention-review', function (): void {
    $days = max(1, (int) config('wayalong_audit.review_after_days', 365));
    $threshold = now()->subDays($days);
    $count = \Illuminate\Support\Facades\DB::table('clinical_access_events')
        ->where('created_at', '<', $threshold)->count();
    $this->info("Audit records older than {$days} days requiring retention review: {$count}");
    $this->line('Read-only report. No records were deleted. Keep records until an approved retention policy authorizes disposal.');
})->purpose('Review audit retention without modifying or deleting audit records');
