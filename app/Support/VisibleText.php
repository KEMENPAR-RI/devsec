<?php

namespace App\Support;

/**
 * Splits crawled text into plain runs and visible markers for invisible/control
 * characters (zero-width, bidi overrides, C0/C1, invalid bytes), so they are
 * shown rather than silently dropped. Bidi overrides matter: Trojan Source.
 */
class VisibleText
{
    protected const MARKED = '/([\x{0000}-\x{0008}\x{000B}-\x{001F}\x{007F}-\x{009F}\x{00AD}\x{034F}\x{061C}\x{115F}\x{1160}\x{17B4}\x{17B5}\x{180E}'
        .'\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{2066}-\x{2069}\x{3164}\x{FE00}-\x{FE0F}\x{FEFF}\x{FFA0}\x{FFFD}\x{E0000}-\x{E007F}])/u';

    /**
     * @return list<array{0: string, 1: bool}> [text, marker]; a marker's text is its label (U+200B, CR)
     */
    public static function tokens(string $text): array
    {
        if ($text === '') {
            return [];
        }

        $parts = preg_split(self::MARKED, self::scrub($text), -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

        if ($parts === false) {
            return [[self::scrub($text), false]];
        }

        $tokens = [];

        foreach ($parts as $part) {
            if (preg_match(self::MARKED, $part) && mb_strlen($part) === 1) {
                $tokens[] = [self::label($part), true];
            } else {
                $tokens[] = [$part, false];
            }
        }

        return $tokens;
    }

    /** Text with every marked character written as [U+XXXX] / [CR] - for the plain-text export. */
    public static function plain(string $text): string
    {
        $out = '';

        foreach (self::tokens($text) as [$value, $marker]) {
            $out .= $marker ? '['.$value.']' : $value;
        }

        return $out;
    }

    protected static function label(string $char): string
    {
        $code = mb_ord($char, 'UTF-8');

        return $code === 0x0D ? 'CR' : sprintf('U+%04X', $code);
    }

    /** Invalid UTF-8 bytes become U+FFFD, which is then marked. */
    protected static function scrub(string $text): string
    {
        if (mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }

        $previous = mb_substitute_character();
        mb_substitute_character(0xFFFD);

        try {
            return mb_scrub($text, 'UTF-8');
        } finally {
            mb_substitute_character($previous);
        }
    }
}
