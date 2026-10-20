<?php

namespace App\Support;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Caps how much of a response is kept in memory: the body is cut at the limit
 * while it streams (after gzip decoding). Content-Length is deliberately ignored -
 * refusing on it would let a padded page escape judgement altogether.
 */
class ResponseSizeLimit implements StreamInterface
{
    use StreamDecoratorTrait;

    /** Past this many times the limit the transfer is aborted (gzip bomb). */
    protected const ABORT_FACTOR = 4;

    public bool $truncated = false;

    protected int $received = 0;

    protected StreamInterface $stream;

    public function __construct(protected int $max)
    {
        $this->stream = Utils::streamFor(Utils::tryFopen('php://temp', 'r+'));
    }

    /** Wraps a Guzzle handler: a fresh capped sink for every request, retry and redirect hop. */
    public static function handler(callable $inner, ?int $max = null): callable
    {
        $max ??= static::max();

        return function (RequestInterface $request, array $options) use ($inner, $max) {
            $options['sink'] = new static($max);

            return $inner($request, $options);
        };
    }

    public static function max(): int
    {
        return max(1024, (int) config('monitoring.max_response_bytes', 5 * 1024 * 1024));
    }

    /** Curl writes the decoded body here; bytes past the limit are dropped. */
    public function write(string $string): int
    {
        $length = strlen($string);
        $this->received += $length;

        // A short write makes curl abort the transfer.
        if ($this->received > $this->max * self::ABORT_FACTOR) {
            return 0;
        }

        $room = $this->max - (int) $this->stream->getSize();

        if ($room <= 0 || $length > $room) {
            $this->truncated = true;
        }

        if ($room > 0) {
            $this->stream->write(substr($string, 0, $room));
        }

        return $length;
    }
}
