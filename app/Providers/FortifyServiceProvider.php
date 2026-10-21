<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Http\Controllers\OrganizationLoginController;
use App\Support\OrganizationContext;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use App\Actions\Fortify\ResetUserPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
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
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
        Fortify::authenticateUsing(function (Request $request): ?User {
            $loginOrganizationId = $request->session()->get(OrganizationLoginController::LOGIN_ORGANIZATION_KEY);
            $query = User::query()->where('email', $request->input('email'));
            if ($loginOrganizationId !== null) {
                // Identity is global; the requested organization's active membership
                // provides authorization, regardless of the transitional owner field.
                $query->whereHas('organizations', function ($builder) use ($loginOrganizationId) {
                    $builder->whereKey($loginOrganizationId)
                        ->where('organizations.status', 'active')
                        ->where('organization_user.status', 'active');
                });
            }

            // Never select an ambiguous identity. Login emails remain globally
            // unique under the central identity model.
            $matches = $query->limit(2)->get();
            if ($matches->count() !== 1) {
                return null;
            }
            $user = $matches->first();

            return $user && $user->is_active && Hash::check((string) $request->input('password'), $user->password)
                ? $user : null;
        });

        Event::listen(Login::class, function (Login $event): void {
            $request = request();
            if (! $request->hasSession()) return;
            $organizationId = $request->session()->pull(OrganizationLoginController::LOGIN_ORGANIZATION_KEY);
            if ($organizationId === null || ! $event->user instanceof User) return;
            if ($event->user->organizations()->whereKey($organizationId)
                ->where('organizations.status', 'active')->wherePivot('status', 'active')->exists()) {
                $request->session()->put(OrganizationContext::SESSION_KEY, $organizationId);
            }
        });
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/Login', [
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
            'status' => $request->session()->get('status'),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/ResetPassword', [
            'email' => $request->email,
            'token' => $request->route('token'),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/ForgotPassword', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::verifyEmailView(fn (Request $request) => Inertia::render('auth/VerifyEmail', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::registerView(fn () => Inertia::render('auth/Register', [
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::twoFactorChallengeView(fn () => Inertia::render('auth/TwoFactorChallenge'));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/ConfirmPassword'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('passkeys', function (Request $request) {
            return Limit::perMinute(10)->by(
                ($request->input('credential.id') ?: $request->session()->getId()).'|'.$request->ip(),
            );
        });
    }
}
