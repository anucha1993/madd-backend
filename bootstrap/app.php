<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Pure API backend — there is no 'login' named route anywhere in this app. Without this,
        // any unauthenticated request that doesn't send an "Accept: application/json" header
        // (e.g. opening a label PDF in a new browser tab) hits Laravel's default guest-redirect
        // logic, which tries route('login') and crashes with RouteNotFoundException instead of
        // returning a clean 401. Never redirect guests — always fall through to a JSON 401.
        $middleware->redirectGuestsTo(fn () => null);

        $middleware->alias([
            'perm' => \App\Http\Middleware\RequirePermission::class,
            'record.scope' => \App\Http\Middleware\EnsureRecordInScope::class,
            'api.client' => \App\Http\Middleware\AuthenticateApiClient::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        // Every reported failure (uncaught 500s, R2 uploads, UPS paperless…) also lands in
        // Config › System Alerts. Validation/auth/404 exceptions are in dontReport and skip this.
        $exceptions->report(function (Throwable $e) {
            \App\Models\SystemAlert::recordException($e);
        });
    })->create();
