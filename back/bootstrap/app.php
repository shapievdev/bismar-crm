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
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Enables Sanctum's cookie-based SPA authentication: requests coming from a
        // domain listed in `sanctum.stateful` are authenticated via the session.
        $middleware->statefulApi();

        // There is no login page to send a guest to — the sign-in form belongs to
        // the Nuxt application. Without this, the `auth` middleware falls back to
        // the framework default of `route('login')`, which is undefined here, and
        // an unauthenticated request that does not ask for JSON dies with a 500
        // instead of the 401 the handler below would have rendered.
        $middleware->redirectGuestsTo(null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
