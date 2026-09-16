<?php

namespace App\Rules;

use App\Services\Security\OutboundUrlGuard;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects an address this server must never fetch - loopback, intranet, or a
 * cloud metadata endpoint. Pair it with Laravel's own 'url' rule, which only
 * checks the shape.
 */
class PublicUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        if ($reason = OutboundUrlGuard::reason($value)) {
            $fail($reason);
        }
    }
}
