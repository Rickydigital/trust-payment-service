<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'auth.delegated' => \App\Http\Middleware\AuthenticateViaMainPlatform::class,
            'auth.internal' => \App\Http\Middleware\AuthenticateInternalService::class,
            'auth.internal_or_delegated' => \App\Http\Middleware\AuthenticateInternalOrDelegated::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
    $exceptions->shouldRenderJsonWhen(function (Illuminate\Http\Request $request, Throwable $e) {
        // Force JSON error responses for every request under /api,
        // regardless of what Accept header the caller sent. Flutter,
        // ClickPesa's webhook caller, and the main platform's
        // server-to-server calls won't reliably send
        // "Accept: application/json" — without this, Laravel's default
        // exception handler falls back to an HTML redirect for
        // unauthenticated/validation errors, which is invisible and
        // confusing for an API-only service.
        if ($request->is('api/*')) {
            return true;
        }

        return $request->expectsJson();
    });
})->create();
