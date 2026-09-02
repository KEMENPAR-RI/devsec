<?php

use App\Http\Middleware\EnsureUserIsNotBanned;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Runs on every web request so a ban applied mid-session takes effect on
        // that session's very next click, not whenever it happens to expire.
        $middleware->appendToGroup('web', EnsureUserIsNotBanned::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
