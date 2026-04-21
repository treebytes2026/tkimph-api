<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

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
        $this->configureRateLimiting();

        $frontend = rtrim((string) config('app.frontend_url'), '/');

        ResetPassword::createUrlUsing(function (object $notifiable, string $token) use ($frontend) {
            return $frontend.'/reset-password?'.http_build_query([
                'token' => $token,
                'email' => $notifiable->getEmailForPasswordReset(),
            ]);
        });
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request) {
            $identity = $request->user()?->getAuthIdentifier() ?: $request->ip();

            return [
                Limit::perMinute(config('security.rate_limits.api_per_minute'))->by('api:'.$identity),
                Limit::perMinute(config('security.rate_limits.api_ip_per_minute'))->by('api-ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('public', function (Request $request) {
            return Limit::perMinute(config('security.rate_limits.public_api_per_minute'))
                ->by('public:'.$request->ip());
        });

        RateLimiter::for('applications', function (Request $request) {
            return Limit::perMinutes(10, config('security.rate_limits.applications_per_10_minutes'))
                ->by('applications:'.$request->ip());
        });

        RateLimiter::for('login', function (Request $request) {
            $email = Str::lower((string) $request->input('email'));

            return [
                Limit::perMinute(config('security.rate_limits.login_per_minute'))->by('login:'.$email.'|'.$request->ip()),
                Limit::perMinutes(15, config('security.rate_limits.login_ip_per_15_minutes'))->by('login-ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('uploads', function (Request $request) {
            $identity = $request->user()?->getAuthIdentifier() ?: $request->ip();

            return Limit::perMinutes(10, config('security.rate_limits.uploads_per_10_minutes'))
                ->by('uploads:'.$identity);
        });

        RateLimiter::for('passwords', function (Request $request) {
            return Limit::perMinutes(10, config('security.rate_limits.passwords_per_10_minutes'))
                ->by('passwords:'.$request->ip());
        });
    }
}
