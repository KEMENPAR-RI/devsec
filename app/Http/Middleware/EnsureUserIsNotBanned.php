<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends the session of an account that was banned while it was still signed in.
 *
 * Without this, a ban would only take effect at the next login - the offending
 * session would keep working until it expired on its own, which is exactly the
 * window a ban exists to close.
 */
class EnsureUserIsNotBanned
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check() && Auth::user()->isBanned()) {
            $reason = Auth::user()->banned_reason;

            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'email' => $reason
                    ? "Akun Anda diblokir: {$reason}"
                    : 'Akun Anda diblokir. Hubungi administrator.',
            ]);
        }

        return $next($request);
    }
}
