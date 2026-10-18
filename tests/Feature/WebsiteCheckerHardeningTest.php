<?php

namespace Tests\Feature;

use App\Models\Incident;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteCheck;
use App\Services\Monitoring\WebsiteChecker;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException as GuzzleRequestException;
use GuzzleHttp\Promise\Create;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * Pentest fixes on the probe: bounded error_message, errno-based SSL fallback,
 * and a total per-check deadline across retries and the fallback.
 */
class WebsiteCheckerHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Sleep::fake();
        User::factory()->create();
    }

    public function test_a_long_error_message_is_truncated_and_still_counts_toward_an_incident(): void
    {
        // Remote-controlled tail (e.g. a redirect Location) plus an invalid UTF-8 byte.
        $message = 'cURL error 7: Failed to connect for https://long.example/'.str_repeat('a', 600)."\xff";
        Http::fake(['*' => Http::failedConnection($message)]);
        $website = $this->site(['url' => 'https://long.example', 'down_threshold_failures' => 2]);

        $check = $this->check($website, 2);

        $this->assertLessThanOrEqual(255, mb_strlen($check->error_message));
        $this->assertTrue(mb_check_encoding($check->error_message, 'UTF-8'));
        $this->assertLessThanOrEqual(255, mb_strlen(WebsiteCheck::find($check->id)->error_message));
        $this->assertSame(2, $website->fresh()->consecutive_failures);

        $incident = Incident::where('website_id', $website->id)->where('type', 'downtime')->sole();
        $this->assertLessThanOrEqual(255, mb_strlen($incident->description));
        $this->assertLessThanOrEqual(255, mb_strlen($incident->meta['error_message']));
    }

    public function test_no_ssl_fallback_when_only_the_url_mentions_ssl(): void
    {
        $verify = [];
        Http::fake(['*' => function ($request, $options) use (&$verify) {
            $verify[] = $options['verify'] ?? null;

            return $this->reject($request, 56, 'cURL error 56: Recv failure: Connection reset by peer for https://ssl-portal.example/ssl');
        }]);

        $check = $this->check($this->site(['url' => 'https://ssl-portal.example/ssl']));

        $this->assertFalse($check->is_up);
        $this->assertCount(2, $verify, 'retried once, never repeated without verification');
        $this->assertNotContains(false, $verify);
    }

    public function test_a_tls_errno_still_falls_back_without_verification(): void
    {
        Http::fake(['*' => function ($request, $options) {
            return ($options['verify'] ?? true) === false
                ? Http::response('ok', 200)
                : $this->reject($request, 60, 'cURL error 60: SSL certificate problem: unable to get local issuer certificate');
        }]);

        $check = $this->check($this->site());

        $this->assertTrue($check->is_up);
    }

    public function test_the_deadline_stops_further_retries_and_the_ssl_fallback(): void
    {
        $this->freezeTime();
        $attempts = 0;
        Http::fake(['*' => function ($request) use (&$attempts) {
            $attempts++;
            // Timeout 10s + 5s margin: 12s spent leaves too little for another attempt.
            $this->travel(12)->seconds();

            return $this->reject($request, 60, 'cURL error 60: SSL certificate problem: certificate has expired');
        }]);

        $check = $this->check($this->site());

        $this->assertSame(1, $attempts);
        $this->assertFalse($check->is_up);
    }

    public function test_a_retry_only_gets_the_time_left_in_the_budget(): void
    {
        $this->freezeTime();
        $timeouts = [];
        Http::fake(['*' => function ($request, $options) use (&$timeouts) {
            $timeouts[] = $options['timeout'];
            $this->travel(8)->seconds();

            return $this->reject($request, 7, 'cURL error 7: Failed to connect to flaky.example port 443');
        }]);

        $this->check($this->site());

        $this->assertCount(2, $timeouts);
        $this->assertEquals(10, $timeouts[0]);
        $this->assertEqualsWithDelta(7, $timeouts[1], 0.01);
    }

    public function test_an_invalid_utf8_byte_inside_a_short_message_is_scrubbed(): void
    {
        // Under the 250-char cut, so only mb_scrub (not Str::limit) can make it valid UTF-8.
        Http::fake(['*' => Http::failedConnection("cURL error 7: Failed to connect to bad\xffhost port 443")]);

        $check = $this->check($this->site());

        $this->assertTrue(mb_check_encoding($check->error_message, 'UTF-8'));
    }

    public function test_a_tls_errno_on_a_guzzle_request_exception_still_falls_back(): void
    {
        // Real curl raises errno 60 as a RequestException without response, not a ConnectException.
        Http::fake(['*' => function ($request, $options) {
            return ($options['verify'] ?? true) === false
                ? Http::response('ok', 200)
                : Create::rejectionFor(new GuzzleRequestException(
                    'cURL error 60: SSL certificate problem: unable to get local issuer certificate',
                    $request->toPsrRequest(), null, null, ['errno' => 60],
                ));
        }]);

        $this->assertTrue($this->check($this->site())->is_up);
    }

    /**
     * Regression: for a non-successful, non-failed response (3xx without
     * Location, e.g. 304) Laravel passes $response->toException() === null to the retry
     * `when` callback, which is typed Throwable -> TypeError -> recorded as a 'connect' error.
     */
    public function test_a_304_response_is_not_recorded_as_a_connection_error(): void
    {
        $attempts = 0;
        Http::fake(['*' => function () use (&$attempts) {
            $attempts++;

            return Http::response('', 304);
        }]);

        $check = $this->check($this->site());

        $this->assertNull($check->error_type, (string) $check->error_message);
        $this->assertSame(304, $check->status_code);
        $this->assertTrue($check->is_up);
        $this->assertSame(1, $attempts);
    }

    /**
     * Regression: a TLS errno must not be retried with verification first, or a slow
     * handshake that fails twice spends the budget the no-verify fallback needed.
     */
    public function test_a_slow_tls_failure_still_leaves_budget_for_the_ssl_fallback(): void
    {
        $this->freezeTime();
        Http::fake(['*' => function ($request, $options) {
            if (($options['verify'] ?? true) === false) {
                return Http::response('ok', 200);
            }

            // 6s handshake then certificate error; budget is 10s + 5s.
            $this->travel(6)->seconds();

            return $this->reject($request, 60, 'cURL error 60: SSL certificate problem: unable to get local issuer certificate');
        }]);

        $this->assertTrue($this->check($this->site())->is_up);
    }

    public function test_qa_a_connect_error_on_a_host_named_ssl_is_labelled_connect(): void
    {
        // errno first: the host name in the message must not decide the label.
        Http::fake(['*' => fn ($request) => $this->reject($request, 7, 'cURL error 7: Failed to connect to ssl-portal.example port 443: Connection refused')]);

        $check = $this->check($this->site(['url' => 'https://ssl-portal.example']));

        $this->assertSame('connect', $check->error_type);
    }

    public function test_qa_a_timeout_on_a_host_named_timed_out_is_retried_by_errno(): void
    {
        // errno 7 whose text says "timed out": errno wins, so the retry still happens.
        $attempts = 0;
        Http::fake(['*' => function ($request) use (&$attempts) {
            return ++$attempts === 1
                ? $this->reject($request, 7, 'cURL error 7: Failed to connect to x.example port 443: Connection timed out')
                : Http::response('ok', 200);
        }]);

        $this->assertTrue($this->check($this->site())->is_up);
        $this->assertSame(2, $attempts);
    }

    protected function reject($request, int $errno, string $message)
    {
        return Create::rejectionFor(new ConnectException($message, $request->toPsrRequest(), null, ['errno' => $errno]));
    }

    protected function site(array $attributes = []): Website
    {
        static $sequence = 0;

        // 5000 ms threshold => 10s per-attempt timeout, 15s total budget.
        return Website::factory()->create([
            'url' => 'https://harden-'.(++$sequence).'.example',
            'response_time_threshold_ms' => 5000,
            ...$attributes,
        ]);
    }

    protected function check(Website $website, int $times = 1): WebsiteCheck
    {
        $checker = app(WebsiteChecker::class);

        foreach (range(1, $times) as $ignored) {
            $check = $checker->check($website->fresh());
        }

        return $check;
    }
}
