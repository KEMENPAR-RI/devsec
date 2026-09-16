<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Holds a signed-in user on their profile page until they enrol a second factor,
 * when Pengaturan says 2FA is mandatory.
 *
 * The profile page and logout stay reachable, or the requirement would be a
 * lockout rather than a prompt - there would be nowhere to go to satisfy it.
 * A user with no second factor can therefore do exactly two things: enrol, or
 * sign out.
 */
class RequireTwoFactor
{
    /** Routes a user without a second factor may still reach. */
    protected const ALLOWED_ROUTES = [
        'profil', 'logout', 'login', 'two-factor.challenge',
        // The enrolment form is a Livewire component, and its button presses post
        // here rather than to /profil - blocking this would block enrolling.
        // Reaching any other component still needs an initial render, which is
        // exactly what this middleware refuses.
        'livewire.update',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check() || Auth::user()->hasTwoFactorEnabled()) {
            return $next($request);
        }

        // Read straight from Setting rather than a service: this runs on every
        // request, and the value is already served from the per-request memo.
        if (Setting::get('two_factor_required') !== '1') {
            return $next($request);
        }

        if ($request->routeIs(self::ALLOWED_ROUTES)) {
            return $next($request);
        }

        return redirect()->route('profil')->with(
            'twoFactorStatus',
            'Verifikasi dua faktor diwajibkan. Aktifkan di bawah ini untuk melanjutkan.'
        );
    }
}
