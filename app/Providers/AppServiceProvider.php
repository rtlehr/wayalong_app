<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->protectTestingDatabase();
    }

    /**
     * Prevent the testing environment from using
     * an unapproved database.
     */
    private function protectTestingDatabase(): void
    {
        if (! $this->app->environment('testing')) {
            return;
        }

        $connection = config('database.default');

        if ($connection !== 'mysql') {
            throw new RuntimeException(
                'WAYALONG TEST SAFETY: MySQL is required for testing.'
            );
        }

        $settings = config('database.connections.mysql', []);

        $database = $settings['database'] ?? null;
        $url = $settings['url'] ?? null;

        // Reject connection URLs that could override
        // the explicitly configured database name.
        if (! empty($url)) {
            throw new RuntimeException(
                'WAYALONG TEST SAFETY: DB_URL must be empty during tests.'
            );
        }

        if ($database !== 'wayalong_testing') {
            throw new RuntimeException(
                'WAYALONG TEST SAFETY: Tests must use wayalong_testing. '
                . 'Configured database: '
                . ($database ?: '(not set)')
            );
        }
    }
}
