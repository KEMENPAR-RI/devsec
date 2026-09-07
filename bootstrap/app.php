<?php

use App\Http\Middleware\EnsureUserIsNotBanned;
use App\Http\Middleware\SecurityHeaders;
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

        // Global rather than web-only: the PWA manifest and icon routes sit outside
        // the web group, and a response without these headers is exactly the kind
        // of gap that is easy to leave and hard to notice.
        $middleware->append(SecurityHeaders::class);

        // In production this app sits behind a reverse proxy that terminates TLS
        // (aaPanel/openlitespeed), so PHP itself only ever sees a plain HTTP
        // connection. Without this, $request->secure() is false on a request the
        // visitor made over HTTPS - which would silently suppress the HSTS header
        // in exactly the deployment it exists for, and generate http:// asset URLs
        // on an https:// page.
        //
        // '*' trusts whatever fronts the app; that is correct only because nothing
        // but the proxy can reach PHP directly. If the app is ever exposed without
        // one, narrow this to the proxy's address - a spoofed X-Forwarded-Proto
        // would otherwise let a client decide it is "secure" on its own say-so.
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
