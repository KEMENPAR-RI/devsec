<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The hardening headers are the kind of thing that gets silently dropped by a
 * middleware reshuffle and noticed months later by an audit, so each one is
 * pinned here rather than trusted to stay put.
 */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_content_sniffing_is_blocked(): void
    {
        $this->get('/login')->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_the_app_cannot_be_framed(): void
    {
        $response = $this->get('/login');

        $response->assertHeader('X-Frame-Options', 'DENY');
        // The header above is the legacy half; frame-ancestors is what a current
        // browser actually enforces, so a clickjacking defence needs both.
        $this->assertStringContainsString(
            "frame-ancestors 'none'",
            $response->headers->get('Content-Security-Policy')
        );
    }

    public function test_a_content_security_policy_restricts_where_scripts_may_come_from(): void
    {
        $csp = $this->get('/login')->headers->get('Content-Security-Policy');

        $this->assertNotNull($csp, 'no CSP means no XSS protection worth the name');
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        // An injected <script src="//attacker.example"> is the case this blocks:
        // the origin is not in script-src, so the browser refuses to fetch it.
        $this->assertStringContainsString("script-src 'self'", $csp);
    }

    public function test_form_posts_and_base_urls_are_pinned_to_this_origin(): void
    {
        $csp = $this->get('/login')->headers->get('Content-Security-Policy');

        // Both are ways injected markup redirects a submitted password elsewhere.
        $this->assertStringContainsString("form-action 'self'", $csp);
        $this->assertStringContainsString("base-uri 'self'", $csp);
    }

    public function test_hsts_is_not_sent_over_plain_http(): void
    {
        // Sending it here would be pointless (a browser ignores HSTS from an
        // insecure origin) and would break local development, which is http://.
        $this->get('/login')->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_hsts_over_https_carries_the_directives_preload_requires(): void
    {
        $header = $this->get('https://deadsec.apiprs.my.id/login')
            ->headers->get('Strict-Transport-Security');

        $this->assertNotNull($header);
        // hstspreload.org rejects a submission missing any of these three.
        $this->assertStringContainsString('max-age=31536000', $header);
        $this->assertStringContainsString('includeSubDomains', $header);
        $this->assertStringContainsString('preload', $header);
    }

    public function test_the_legacy_xss_filter_header_is_not_reintroduced(): void
    {
        // X-XSS-Protection is deliberately absent: the filter it enabled was
        // removed from Chrome and Edge, and its block mode was itself exploitable.
        // Anyone adding it back is undoing a decision, not filling a gap.
        $this->get('/login')->assertHeaderMissing('X-XSS-Protection');
    }

    public function test_the_vite_dev_server_is_allowed_while_it_is_running(): void
    {
        // Regression: the policy allowed only 'self', so with `npm run dev` up the
        // browser blocked every asset @vite pointed at the dev server's port. The
        // app rendered as unstyled HTML with nothing logged anywhere - the failure
        // looks like a broken build, not a blocked request.
        $hot = public_path('hot');
        $existed = is_file($hot);
        $previous = $existed ? file_get_contents($hot) : null;

        file_put_contents($hot, 'http://127.0.0.1:5173');

        try {
            $csp = $this->get('/login')->headers->get('Content-Security-Policy');

            $this->assertStringContainsString('http://127.0.0.1:5173', $csp, 'dev assets would be blocked');
            $this->assertStringContainsString('ws://127.0.0.1:5173', $csp, 'hot reload would be blocked');
        } finally {
            $existed ? file_put_contents($hot, $previous) : @unlink($hot);
        }
    }

    public function test_an_ipv6_dev_origin_is_not_emitted(): void
    {
        // CSP cannot express a bracketed IPv6 literal: a browser drops the whole
        // source entry and says so only in the console, leaving a header that looks
        // permissive while enforcing something stricter. Emitting nothing is the
        // honest outcome - vite.config.js pins the dev server to 127.0.0.1 so this
        // should not happen, but a stale public/hot from before that change would.
        $hot = public_path('hot');
        $existed = is_file($hot);
        $previous = $existed ? file_get_contents($hot) : null;

        file_put_contents($hot, 'http://[::1]:5173');

        try {
            $csp = $this->get('/login')->headers->get('Content-Security-Policy');

            $this->assertStringNotContainsString('[::1]', $csp);
        } finally {
            $existed ? file_put_contents($hot, $previous) : @unlink($hot);
        }
    }

    public function test_no_dev_origin_leaks_into_the_policy_in_production(): void
    {
        $hot = public_path('hot');
        $existed = is_file($hot);
        $previous = $existed ? file_get_contents($hot) : null;

        // No hot file is what a built deployment looks like: assets come from
        // public/build, which is already 'self'.
        $existed and @unlink($hot);

        try {
            $csp = $this->get('/login')->headers->get('Content-Security-Policy');

            $this->assertStringNotContainsString('5173', $csp);
            $this->assertStringNotContainsString('ws://', $csp);
        } finally {
            $existed and file_put_contents($hot, $previous);
        }
    }

    public function test_headers_reach_routes_outside_the_web_middleware_group(): void
    {
        // The PWA manifest is served by its own route with its own headers - the
        // shape of response that a web-group-only middleware would quietly miss.
        $this->get('/manifest.webmanifest')->assertHeader('X-Content-Type-Options', 'nosniff');
    }
}
