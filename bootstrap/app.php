<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureAccountSession;
use App\Http\Middleware\EnsureTransactionReady;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Laravel's web middleware provides sessions and CSRF protection.
        $middleware->prepend(AssignRequestId::class);
        $middleware->alias([
            'account.session' => EnsureAccountSession::class,
            'transaction.ready' => EnsureTransactionReady::class,
        ]);
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
