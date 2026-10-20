<?php

namespace App\Services\Monitoring;

use Illuminate\Support\Str;
use Normalizer;

/**
 * Text normalization shared by judol matching, content-change and defacement.
 *
 * clean() gives readable text (snapshots, AI input, excerpts); fold() gives a
 * matching skeleton and is applied identically to page text AND to keywords,
 * exclusions and signatures, so both sides always meet in the same alphabet.
 */
class TextNormalizer
{
    /** Zero-width, bidi, soft hyphen, variation selectors. */
    protected const INVISIBLE = '/[\x{00AD}\x{034F}\x{180E}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{FEFF}\x{FE00}-\x{FE0F}]/u';

    /** Boundary token fold() leaves where a sentence/clause ends - never inside a pattern. */
    protected const BOUNDARY = "\x02";

    protected const LEET_SYMBOLS = ['@' => 'a', '$' => 's', '!' => 'i', '|' => 'i'];

    protected const LEET_DIGITS = ['0' => 'o', '1' => 'i', '3' => 'e', '4' => 'a', '5' => 's', '7' => 't', '8' => 'b', '9' => 'g'];

    protected const LIGATURES = ['ﬀ' => 'ff', 'ﬁ' => 'fi', 'ﬂ' => 'fl', 'ﬃ' => 'ffi', 'ﬄ' => 'ffl', 'ﬅ' => 'st', 'ﬆ' => 'st'];

    protected const MONTHS = 'januari|februari|maret|april|mei|juni|juli|agustus|september|oktober|november|desember|jan|feb|mar|apr|jun|jul|agu|agt|aug|sep|okt|oct|nov|des|dec|january|february|march|may|june|july|august|october|december';

    protected const DAYS = 'senin|selasa|rabu|kamis|jumat|jum\'at|sabtu|minggu|monday|tuesday|wednesday|thursday|friday|saturday|sunday';

    /** @var array<string, string>|null */
    protected static ?array $homoglyphs = null;

    /** Readable text: entities decoded, invisibles dropped, NFKC, squished. */
    public static function clean(string $text): string
    {
        if ($text === '') {
            return '';
        }

        // DOM already decoded one level; this catches double-encoded entities.
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace(self::INVISIBLE, '', $text) ?? $text;
        $text = preg_replace('/[\p{Z}\x{00A0}]+/u', ' ', $text) ?? $text;
        $text = self::nfkc($text);

        return Str::squish($text);
    }

    /** Matching skeleton wrapped in sentinel spaces: ' slot gacor '. */
    public static function fold(string $text): string
    {
        $text = mb_strtolower(self::clean($text), 'UTF-8');

        if ($text === '') {
            return ' ';
        }

        $text = self::stripDiacritics($text);
        $text = strtr($text, self::homoglyphs());

        // Single characters split by separators: s.l.o.t, s l o t, s|l|o|t. Before
        // symbol leet, or '|' would turn into letters first.
        $text = preg_replace_callback(
            '/(?<![\p{L}\p{N}])(?:[\p{L}\p{N}][\s.\-_*·•|\/]{1,3}){2,}[\p{L}\p{N}](?![\p{L}\p{N}])/u',
            fn (array $m) => preg_replace('/[^\p{L}\p{N}]+/u', '', $m[0]),
            $text
        ) ?? $text;

        // Symbol leetspeak inside a token: sl@t, $lot, g4c!r.
        $text = preg_replace_callback(
            '/(?<=[\p{L}\p{N}])[@$!|](?=[\p{L}\p{N}])|[@$](?=\p{L})/u',
            fn (array $m) => self::LEET_SYMBOLS[$m[0]],
            $text
        ) ?? $text;

        // Sentence/clause punctuation followed by a space stays a boundary token, so
        // phrases never match across it ("Bapak Toto. Slot ..." is not "toto slot").
        $text = preg_replace('/[.,;!?]+[^\p{L}\p{N}\s]*\s/u', ' '.self::BOUNDARY.' ', $text) ?? $text;
        $text = trim(preg_replace('/[^\p{L}\p{N}'.self::BOUNDARY.']+/u', ' ', $text) ?? $text);

        // Digit leet only in mixed letter+digit tokens; 1/l/i collapse to i everywhere.
        $tokens = explode(' ', $text);

        foreach ($tokens as &$token) {
            if (preg_match('/\p{L}/u', $token) && preg_match('/\d/', $token)) {
                $token = strtr($token, self::LEET_DIGITS);
            }

            $token = str_replace('l', 'i', $token);
        }

        unset($token);

        return ' '.implode(' ', $tokens).' ';
    }

    /** A keyword/exclusion/signature folded the same way, without sentinels. */
    public static function foldKeyword(string $keyword): string
    {
        return trim(self::fold($keyword));
    }

    /** Masks dynamic fragments (dates, times, counters, tokens) before hashing/diffing. */
    public static function maskDynamic(string $text): string
    {
        $patterns = [
            // Relative time: "5 menit yang lalu", "3 hours ago".
            '/\b\d+\s*(detik|menit|jam|hari|minggu|bulan|tahun|seconds?|minutes?|hours?|days?|weeks?|months?|years?)\s*(yang\s+)?(lalu|ago)\b/iu' => '@',
            '/\b('.self::DAYS.')\b,?/iu' => '@',
            '/\b\d{1,2}\s+('.self::MONTHS.')\.?\s+\d{2,4}\b/iu' => '@',
            '/\b('.self::MONTHS.')\.?\s+\d{1,2},?\s+\d{2,4}\b/iu' => '@',
            '/\b\d{1,4}[\/.\-]\d{1,2}[\/.\-]\d{1,4}\b/u' => '@',
            '/\b\d{1,2}[:.]\d{2}([:.]\d{2})?\s*(wib|wita|wit|am|pm)?\b/iu' => '@',
            // Random tokens: long mixed letter+digit strings (nonces, csrf, cache-busters).
            '/\b(?=[A-Za-z0-9_\-]*\d)(?=[A-Za-z0-9_\-]*[A-Za-z])[A-Za-z0-9_\-]{20,}\b/' => '~',
            '/\d+/' => '#',
        ];

        return preg_replace(array_keys($patterns), array_values($patterns), $text) ?? $text;
    }

    /** A 200-status bot challenge / WAF interstitial - treated like a failed fetch. */
    public static function isChallengePage(PageText $page): bool
    {
        // Real pages are long; challenge pages are a few hundred characters.
        if ($page->textLength > 2000) {
            return false;
        }

        // A "Ray ID" footer alone must not hide a defaced page: the title has to
        // match too, and at least two distinct patterns overall.
        $patterns = (array) config('defacement.challenge_patterns', []);
        $title = Str::lower($page->title);
        $haystack = $title.' '.Str::lower($page->visibleText());
        $hits = array_filter($patterns, fn (string $pattern) => str_contains($haystack, Str::lower($pattern)));

        return count($hits) >= 2 && Str::contains($title, $hits);
    }

    /** "Maintenance", "coming soon" and the like - excluded from the shrink and replaced rules. */
    public static function isBenignPlaceholder(string $text): bool
    {
        return Str::contains(Str::lower($text), (array) config('defacement.benign_patterns', []));
    }

    protected static function nfkc(string $text): string
    {
        if (class_exists(Normalizer::class)) {
            $normalized = Normalizer::normalize($text, Normalizer::FORM_KC);

            return $normalized === false ? $text : $normalized;
        }

        $text = strtr($text, self::LIGATURES);

        // Fullwidth ASCII, then mathematical alphanumerics (bold, italic, ...).
        return preg_replace_callback('/[\x{FF01}-\x{FF5E}\x{1D400}-\x{1D6A3}\x{1D7CE}-\x{1D7FF}]/u', function (array $m) {
            $cp = mb_ord($m[0], 'UTF-8');

            if ($cp <= 0xFF5E) {
                return chr($cp - 0xFEE0);
            }

            if ($cp >= 0x1D7CE) {
                return (string) (($cp - 0x1D7CE) % 10);
            }

            $index = ($cp - 0x1D400) % 52;

            return chr($index < 26 ? 65 + $index : 97 + $index - 26);
        }, $text) ?? $text;
    }

    protected static function stripDiacritics(string $text): string
    {
        $text = strtr($text, ['đ' => 'd', 'ł' => 'l', 'ø' => 'o', 'ß' => 'ss', 'æ' => 'ae', 'œ' => 'oe']);

        if (class_exists(Normalizer::class)) {
            $decomposed = Normalizer::normalize($text, Normalizer::FORM_D);

            return $decomposed === false ? $text : (preg_replace('/\p{Mn}+/u', '', $decomposed) ?? $text);
        }

        return preg_replace_callback('/[\x{00C0}-\x{024F}]/u', fn (array $m) => Str::ascii($m[0]), $text) ?? $text;
    }

    /** @return array<string, string> */
    protected static function homoglyphs(): array
    {
        return self::$homoglyphs ??= (array) config('judol.homoglyphs', []);
    }
}
