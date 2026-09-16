<?php

namespace App\Support;

use Illuminate\Support\Str;

/** Renders one stored audit value as something readable in a table cell. */
class AuditValue
{
    public static function render(mixed $value): string
    {
        return match (true) {
            $value === null || $value === '' => '(kosong)',
            is_bool($value) => $value ? 'ya' : 'tidak',
            is_array($value) => Str::limit(implode(', ', array_map(
                fn ($item) => is_scalar($item) ? (string) $item : '...',
                $value
            )), 200),
            default => Str::limit((string) $value, 200),
        };
    }
}
