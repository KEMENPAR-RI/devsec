<?php

namespace App\Support;

use App\Services\Monitoring\TextNormalizer;

/**
 * Compact sentence-chunk fingerprints of page text, so a defacement baseline can
 * be compared without keeping its full text.
 *
 * A fingerprint is a packed list of (crc32 of the masked chunk, chunk length) -
 * 6 bytes per chunk, at most MAX_CHUNKS chunks.
 */
class ContentFingerprint
{
    public const MIN_CHUNK_CHARS = 25;

    public const MAX_CHUNKS = 1000;

    public const MAX_CHUNK_CHARS = 500;

    /**
     * Sentence-ish chunks long enough to be worth comparing, unique, in order.
     *
     * @return array<int, string>
     */
    public static function chunks(string $text): array
    {
        $chunks = [];

        foreach (preg_split('/(?<=[.!?])\s+|\n+|\s{2,}|\|/u', $text) ?: [] as $chunk) {
            $chunk = trim($chunk);

            if (mb_strlen($chunk) >= self::MIN_CHUNK_CHARS) {
                $chunks[$chunk] = true;
            }
        }

        return array_keys($chunks);
    }

    public static function fingerprint(string $clean): string
    {
        $packed = '';

        foreach (array_slice(self::chunks($clean), 0, self::MAX_CHUNKS) as $chunk) {
            $packed .= pack('Nn', crc32(TextNormalizer::maskDynamic($chunk)), min(65535, mb_strlen($chunk)));
        }

        return $packed;
    }

    /** Number of chunks in a fingerprint. */
    public static function count(?string $fingerprint): int
    {
        return intdiv(strlen((string) $fingerprint), 6);
    }

    /**
     * Share of the baseline's text (by length) that no longer appears now: 0 = all
     * still there, 1 = everything replaced.
     */
    public static function replacedRatio(?string $baselineFingerprint, string $currentFingerprint): float
    {
        $baseline = self::unpack($baselineFingerprint);

        if ($baseline === []) {
            return 0.0;
        }

        $current = [];

        foreach (self::unpack($currentFingerprint) as [$crc]) {
            $current[$crc] = true;
        }

        $total = 0;
        $missing = 0;

        foreach ($baseline as [$crc, $length]) {
            $total += $length;

            if (! isset($current[$crc])) {
                $missing += $length;
            }
        }

        return $total > 0 ? round($missing / $total, 4) : 0.0;
    }

    /**
     * Chunks added/removed between two texts, compared in masked form but shown as
     * written. The *_total counts are before the $max cut.
     *
     * @return array{added: array<int, string>, removed: array<int, string>, added_total: int, removed_total: int}
     */
    public static function diff(string $beforeClean, string $afterClean, int $max = 8): array
    {
        $before = self::masked(self::chunks($beforeClean));
        $after = self::masked(self::chunks($afterClean));

        $limit = fn (array $chunks) => array_values(array_map(
            fn (string $chunk) => mb_strimwidth($chunk, 0, self::MAX_CHUNK_CHARS, '…'),
            array_slice($chunks, 0, $max)
        ));

        $added = array_diff_key($after, $before);
        $removed = array_diff_key($before, $after);

        return [
            'added' => $limit($added),
            'removed' => $limit($removed),
            'added_total' => count($added),
            'removed_total' => count($removed),
        ];
    }

    /**
     * @param  array<int, string>  $chunks
     * @return array<string, string> masked => original
     */
    protected static function masked(array $chunks): array
    {
        $masked = [];

        foreach ($chunks as $chunk) {
            $masked[TextNormalizer::maskDynamic($chunk)] ??= $chunk;
        }

        return $masked;
    }

    /** @return array<int, array{0: int, 1: int}> */
    protected static function unpack(?string $fingerprint): array
    {
        $entries = [];
        $fingerprint = (string) $fingerprint;

        for ($i = 0, $n = strlen($fingerprint) - 5; $i < $n; $i += 6) {
            $entry = unpack('Ncrc/nlen', substr($fingerprint, $i, 6));
            $entries[] = [$entry['crc'], $entry['len']];
        }

        return $entries;
    }
}
