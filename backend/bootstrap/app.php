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
        // Required for stateful first-party SPA requests using Sanctum.
        // CORS, session cookies and trusted frontend origins still require deployment configuration.
        $middleware->statefulApi();
        // API downloads can be opened by a browser without Accept: application/json.
        // Do not redirect those requests to an undefined named "login" route.
        $middleware->redirectGuestsTo(fn (Request $request) =>
            $request->is('api/*') ? null : '/login'
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request, \Throwable $exception): bool =>
            $request->is('api/*') || $request->expectsJson()
        );
    })->create();
