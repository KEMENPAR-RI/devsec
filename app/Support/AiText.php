<?php

namespace App\Support;

/**
 * Strips Markdown out of model-written prose so it renders as clean text wherever
 * the app prints it as-is (incident analysis panel).
 *
 * Asking for plain text in the prompt helps but is not reliable - models reach for
 * "## Heading" and "**bold**" by habit - so the syntax is removed deterministically
 * on the way in, once, rather than being worked around at every display site.
 */
class AiText
{
    public static function clean(string $text): string
    {
        $lines = collect(preg_split('/\R/', $text))->map(function (string $line) {
            $line = trim($line);

            // A horizontal rule ("---", "***") carries no meaning without styling.
            if (preg_match('/^([-=*_])\1{2,}$/', $line)) {
                return '';
            }

            $line = preg_replace('/^#{1,6}\s*/', '', $line);         // "## Judul"
            $line = preg_replace('/^[-*+]\s+/', '• ', $line);        // "- butir"
            $line = preg_replace('/^(\d+[.)])\s+/', '$1 ', $line);   // "1.  butir"
            $line = preg_replace('/\*\*(.+?)\*\*/', '$1', $line);    // "**tebal**"
            $line = preg_replace('/__(.+?)__/', '$1', $line);        // "__tebal__"
            $line = preg_replace('/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/', '$1', $line);
            $line = preg_replace('/(?<![\w_])_(?!\s)(.+?)(?<!\s)_(?![\w_])/', '$1', $line);
            $line = str_replace('```', '', $line);
            $line = preg_replace('/`(.+?)`/', '$1', $line);          // "`kode`"

            return rtrim($line);
        })
            // Always "\n", never PHP_EOL: this text is stored and rendered as HTML,
            // so it must not pick up CRLF just because the scan ran on Windows.
            ->implode("\n");

        // Stripping headings and rules tends to leave runs of blank lines behind.
        return trim(preg_replace('/\n{3,}/', "\n\n", $lines));
    }
}
