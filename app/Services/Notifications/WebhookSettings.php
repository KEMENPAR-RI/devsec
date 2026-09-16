<?php

namespace App\Services\Notifications;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Config for the push channel that is not email.
 *
 * Email is where an incident gets read eventually; this is where it gets read
 * now. A critical outage at 02:00 sits in an inbox until somebody opens it,
 * whereas a chat notification buzzes a phone - which is the whole difference
 * between a ten-minute outage and a six-hour one.
 *
 * Telegram only: a bot token plus a chat id, which is the one destination this
 * install actually uses. Opt-in and off by default.
 */
class WebhookSettings
{
    public function enabled(): bool
    {
        return Setting::get('webhook_enabled') === '1';
    }

    /** Switched on AND pointed somewhere it can actually reach. */
    public function isConfigured(): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        return filled($this->token()) && filled($this->target());
    }

    public function token(): ?string
    {
        return $this->decrypted('webhook_token');
    }

    public function hasToken(): bool
    {
        return filled(Setting::get('webhook_token'));
    }

    /** The Telegram chat id: a bare number for a person, -100... for a group. */
    public function target(): ?string
    {
        return Setting::get('webhook_target') ?: null;
    }

    /**
     * Severities worth pushing. Defaults to critical + warning: an 'info' event
     * on a phone at 02:00 is how a team learns to silence the channel.
     *
     * @return array<int, string>
     */
    public function severities(): array
    {
        $stored = Setting::get('webhook_severities');

        // Never configured is not the same as cleared: an untouched install gets
        // the sensible default, while someone who explicitly emptied the list
        // still gets critical - silencing the channel outright by accident would
        // be worse than one message too many.
        if ($stored === null) {
            return ['critical', 'warning'];
        }

        $chosen = array_filter(explode(',', (string) $stored));

        return array_values(array_intersect($chosen, ['critical', 'warning', 'info'])) ?: ['critical'];
    }

    public function wants(string $severity): bool
    {
        return in_array($severity, $this->severities(), true);
    }

    /**
     * Stored values are encrypted on save; a row written before that (or by hand)
     * is returned as-is rather than throwing, so a half-migrated setting degrades
     * to "works" instead of "the whole page errors".
     */
    protected function decrypted(string $key): ?string
    {
        $stored = Setting::get($key);

        if (blank($stored)) {
            return null;
        }

        try {
            return Crypt::decryptString($stored);
        } catch (Throwable) {
            return (string) $stored;
        }
    }
}
