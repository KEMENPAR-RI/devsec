<?php

use App\Http\Middleware\EnsureUserIsNotBanned;
use App\Http\Middleware\RequireTwoFactor;
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

        // Runs after the ban check, so a banned account is turned away before it is
        // ever asked to enrol. Only does anything when Pengaturan says 2FA is
        // mandatory - see RequireTwoFactor.
        $middleware->appendToGroup('web', RequireTwoFactor::class);

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
        // Loopback only, NOT '*'. The proxy runs on this same host, so that is the
        // only address these headers can legitimately arrive from - and trusting
        // every address means trusting the whole X-Forwarded-For chain, which
        // leaves request()->ip() equal to the leftmost value the *client* wrote.
        // That value is what the login and password-reset throttle keys are built
        // from (App\Livewire\Auth\Login::throttleKey), so under '*' a password
        // spray walked past the 5-attempt lockout by rotating one header.
        //
        // If the app ever moves behind a proxy on a different host, name that
        // host's address here rather than widening this back to '*'.
        $middleware->trustProxies(at: ['127.0.0.1', '::1']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
