<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the read-only TV wallboard with a shared token instead of a user
 * session: a TV in a NOC cannot hold a login, and the wallboard only ever
 * exposes the same aggregated status counts the dashboard shows.
 *
 * Fails closed: with no token configured every request is refused, so an
 * operator who forgets TV_DISPLAY_TOKEN gets a 403 rather than a public page.
 */
class EnsureTvToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('tv.token');

        $given = $request->query('token', '');

        if (! is_string($expected) || $expected === '' || ! is_string($given) || ! hash_equals($expected, $given)) {
            abort(403);
        }

        return $next($request);
    }
}
