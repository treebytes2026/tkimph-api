<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->throttleApi(
            limiter: 'api',
            redis: filter_var(env('RATE_LIMIT_USE_REDIS', false), FILTER_VALIDATE_BOOL),
        );

        $middleware->trustHosts(at: function (): array {
            $hosts = array_filter(array_map('trim', explode(',', (string) env('TRUSTED_HOSTS', ''))));

            foreach ([env('APP_URL'), env('FRONTEND_URL')] as $url) {
                $host = parse_url((string) $url, PHP_URL_HOST);

                if ($host) {
                    $hosts[] = $host;
                }
            }

            return array_values(array_unique($hosts));
        });

        $middleware->alias([
            'admin' => \App\Http\Middleware\EnsureUserIsAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
