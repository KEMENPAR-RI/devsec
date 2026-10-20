@props(['tokens' => null, 'text' => null])
<?php
// Crawled text as inert characters; invisible/control chars become visible badges.
// Kept on one line with no whitespace between tokens: callers place this inside <pre>.
// Escaped echo only (no raw echo): every token comes from a hostile page.
$__vtList = is_array($tokens) ? $tokens : \App\Support\VisibleText::tokens((string) $text);
$__vtLabel = static function (string $raw): array {
    if (preg_match('/^(U\+[0-9A-F]{4,6}|CR|DEL|NUL)$/i', $raw)) {
        $code = str_starts_with(strtoupper($raw), 'U+') ? hexdec(substr($raw, 2)) : null;

        return [strtoupper($raw), $code];
    }
    $code = $raw === '' ? null : mb_ord(mb_substr($raw, 0, 1, 'UTF-8'), 'UTF-8');

    return [$code === false || $code === null ? '?' : ($code === 0x0D ? 'CR' : sprintf('U+%04X', $code)), $code ?: null];
};
?><?php foreach ($__vtList as $__vtToken): ?><?php if (! empty($__vtToken[1])): ?><?php [$__vtText, $__vtCode] = $__vtLabel((string) $__vtToken[0]); $__vtBidi = $__vtCode !== null && (($__vtCode >= 0x202A && $__vtCode <= 0x202E) || ($__vtCode >= 0x2066 && $__vtCode <= 0x2069)); ?><span class="mx-px inline-block rounded px-1 align-baseline font-sans text-[10px] font-semibold leading-4 ring-1 ring-inset {{ $__vtBidi ? 'bg-red-100 text-red-700 ring-red-500/40 dark:bg-red-500/20 dark:text-red-300' : 'bg-fuchsia-100 text-fuchsia-700 ring-fuchsia-500/30 dark:bg-fuchsia-500/20 dark:text-fuchsia-300' }}" title="{{ $__vtBidi ? 'Karakter kontrol arah teks (bidi) tak terlihat' : 'Karakter tak terlihat' }}: {{ $__vtText }}">{{ $__vtText }}</span><?php else: ?>{{ (string) $__vtToken[0] }}<?php endif; ?><?php endforeach; ?>