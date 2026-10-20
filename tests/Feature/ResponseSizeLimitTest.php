<?php

namespace Tests\Feature;

use App\Support\ResponseSizeLimit;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/** Security fix #4: a big response is cut while streaming, never refused on its header. */
class ResponseSizeLimitTest extends TestCase
{
    public function test_a_body_over_the_limit_is_cut_even_with_a_large_content_length(): void
    {
        $body = '<html><body><h1>Hacked by X</h1>'.str_repeat('a', 5000).'</body></html>';

        $client = new Client(['handler' => HandlerStack::create(ResponseSizeLimit::handler($this->transport($body), 1024))]);
        $response = $client->get('https://pemda.example');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(substr($body, 0, 1024), (string) $response->getBody());
    }

    public function test_a_body_inflating_far_past_the_limit_aborts_the_transfer(): void
    {
        $sink = new ResponseSizeLimit(1024);

        $this->assertSame(2048, $sink->write(str_repeat('a', 2048)));
        $this->assertTrue($sink->truncated);
        // Past 4x the limit a short write makes curl abort (gzip bomb).
        $this->assertSame(0, $sink->write(str_repeat('a', 3000)));
        $this->assertSame(1024, strlen((string) $sink));
    }

    public function test_the_default_limit_cuts_the_body_at_five_megabytes(): void
    {
        $limit = 5 * 1024 * 1024;
        $this->assertSame($limit, config('monitoring.max_response_bytes'));

        $body = str_repeat('a', $limit + 4096);
        $client = new Client(['handler' => HandlerStack::create(ResponseSizeLimit::handler($this->transport($body)))]);

        $this->assertSame($limit, strlen((string) $client->get('https://pemda.example')->getBody()));
    }

    public function test_a_body_under_the_limit_is_untouched(): void
    {
        $body = str_repeat('b', 3000);
        $client = new Client(['handler' => HandlerStack::create(ResponseSizeLimit::handler($this->transport($body), 4096))]);

        $this->assertSame($body, (string) $client->get('https://pemda.example')->getBody());
    }

    /** Writes the body into the sink the way curl does, with a declared length far over the limit. */
    protected function transport(string $body): callable
    {
        return function (RequestInterface $request, array $options) use ($body) {
            $sink = $options['sink'];

            foreach (str_split($body, 512) as $chunk) {
                $sink->write($chunk);
            }

            return Create::promiseFor(new Response(200, ['Content-Length' => (string) (50 * 1024 * 1024)], $sink));
        };
    }
}
