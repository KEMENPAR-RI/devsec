<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds the browser-side hardening headers to every response.
 *
 * These are defence in depth, not a substitute for the escaping and CSRF handling
 * already in place: each one closes a class of attack that only the browser is in
 * a position to stop, and none of them changes what the application itself does.
 *
 * Set here rather than in the web server config so they travel with the code -
 * this app is deployed to aaPanel/openlitespeed in production and runs under a
 * plain PHP server in development, and a header that exists in only one of those
 * is a header nobody can rely on. Where a reverse proxy sets the same header, the
 * proxy's value wins; that is fine, the values below are the conservative ones.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Stops the browser second-guessing a declared Content-Type. Without it, a
        // stored file that we serve as text/plain can still be executed as script
        // if the bytes happen to look like one - which is the whole shape of a
        // content-sniffing attack. This app stores user-supplied logos and
        // favicons and serves a database dump, so that path is real, not
        // theoretical - which is why this one is set on downloads too.
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // A file download has no document to frame and no scripts to police, so the
        // rest is skipped: sending a CSP on a .sql.gz achieves nothing and only
        // risks a browser treating the attachment differently than it otherwise
        // would. The backup route is the one caller this affects.
        if ($response->headers->has('Content-Disposition')) {
            return $response;
        }

        // Clickjacking: nothing here is meant to be embedded anywhere, so no site
        // may frame it - not even this one. frame-ancestors below is the modern
        // equivalent; X-Frame-Options stays for browsers that predate it.
        $response->headers->set('X-Frame-Options', 'DENY');

        // Referrer-Policy is not on the asked-for list, but it belongs with them:
        // several routes carry an id in the path (/situs/{website}), and the
        // default policy leaks those to any third-party host a page talks to.
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Features this app never uses. Denying them means a script that does get
        // injected cannot reach for the camera or the user's location either.
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');

        $this->applyHsts($request, $response);
        $this->applyContentSecurityPolicy($response);

        return $response;
    }

    /**
     * HSTS with preload: after the first successful HTTPS visit the browser refuses
     * to speak plain HTTP to this host at all, which closes the redirect window an
     * active network attacker would otherwise have on every subsequent visit.
     *
     * Only ever sent over HTTPS. Sending it over HTTP is meaningless (the browser
     * ignores it, correctly - the header cannot be trusted on the very connection
     * it is meant to protect), and would poison local development, where the app
     * runs on plain http://localhost.
     */
    protected function applyHsts(Request $request, Response $response): void
    {
        if (! $request->secure()) {
            return;
        }

        // The three directives hstspreload.org requires for submission: at least a
        // year, every subdomain, and the preload token itself.
        //
        // includeSubDomains is the part with teeth. It commits EVERY subdomain of
        // this host to HTTPS-only, including ones not served by this application -
        // an internal tool on http://staging.<domain> stops loading, in a way that
        // cannot be undone quickly, because preload lists ship inside browsers and
        // removal takes months. Submit at https://hstspreload.org only once every
        // subdomain is known to serve HTTPS.
        $response->headers->set(
            'Strict-Transport-Security',
            'max-age=31536000; includeSubDomains; preload'
        );
    }

    /**
     * XSS protection. The legacy X-XSS-Protection header is deliberately NOT sent:
     * its filter was removed from Chrome and Edge, was never in Firefox, and in the
     * browsers that did ship it the "block" mode introduced vulnerabilities of its
     * own. CSP is what actually stops injected script in a current browser, so that
     * is what this sets.
     *
     * script-src is honest about what this app loads rather than aspirational:
     * Livewire and Alpine both evaluate expressions written in HTML attributes
     * (x-data, wire:model), so 'unsafe-eval' and 'unsafe-inline' are load-bearing
     * here - dropping either one breaks every interactive page. That makes this
     * policy a meaningful reduction in attack surface (an injected <script src>
     * pointing at an attacker's host is blocked) rather than a complete defence
     * against injected inline script. Getting the stronger guarantee means moving
     * off Alpine's attribute expressions, which is a much larger change than this.
     */
    protected function applyContentSecurityPolicy(Response $response): void
    {
        $policy = [
            "default-src 'self'",

            // 'unsafe-eval' + 'unsafe-inline': required by Alpine/Livewire, see above.
            // www.google.com + www.gstatic.com: reCAPTCHA v3, loaded on the login and
            // password-reset forms when a site key is configured.
            "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://www.google.com https://www.gstatic.com",

            // 'unsafe-inline' for style: Alpine's :class bindings and the inline
            // style attributes in the dashboard charts both need it.
            "style-src 'self' 'unsafe-inline'",

            // data: covers inline SVG icons and the PWA icons the manifest points at.
            // The uploaded logo and favicon are served from this origin via
            // Storage::disk('public')->url(), so no third-party host is needed.
            "img-src 'self' data:",

            "font-src 'self' data:",

            // Livewire posts back to this origin only. reCAPTCHA's own script talks
            // to google.com, so that origin has to be reachable here too.
            "connect-src 'self' https://www.google.com",

            // reCAPTCHA v3 renders in an invisible iframe served by google.com.
            "frame-src https://www.google.com",

            // The CSP equivalent of X-Frame-Options: DENY, and the one modern
            // browsers actually consult.
            "frame-ancestors 'none'",

            // No <base> tag rewriting, and form posts can only go to this origin -
            // both are ways an injected tag redirects a submitted password.
            "base-uri 'self'",
            "form-action 'self'",

            // Nothing here loads a plugin or an applet.
            "object-src 'none'",

            // public/sw.js backs the installable/offline build. Without this the
            // service worker registration falls to default-src, and a stricter
            // default later would break PWA install with no obvious cause.
            "worker-src 'self'",

            // The PWA manifest is generated by PwaController on this origin.
            "manifest-src 'self'",
        ];

        $response->headers->set('Content-Security-Policy', implode('; ', $policy));
    }
}
