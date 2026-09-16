<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * One line of the "siapa melakukan apa" trail.
 *
 * Append-only by design: nothing in the app updates or deletes a row here, and
 * the Audit page offers no way to. Pruning is the scheduler's job alone
 * (audit:prune), so an operator cannot quietly tidy away their own actions.
 */
class AuditLog extends Model
{
    use HasUuid;

    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id', 'actor_name', 'actor_email', 'event', 'subject',
        'auditable_type', 'auditable_id', 'changes', 'ip_address', 'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Field names whose value must never reach this table, on any model.
     *
     * Only the fact that they changed is recorded. A password hash or an API key
     * copied into an audit row would make the log a second place to steal them
     * from - and the log is deliberately the one table nobody may delete.
     */
    public const REDACTED_FIELDS = [
        'password', 'remember_token', 'anthropic_api_key', 'mail_password',
        'api_key', 'secret', 'token', 'recaptcha_secret_key',
    ];

    /**
     * Writes one line, attributing it to the signed-in user when there is one.
     *
     * @param  array<string, mixed>  $changes
     */
    public static function record(string $event, ?string $subject = null, array $changes = [], ?Model $auditable = null): ?self
    {
        $user = Auth::user();
        $request = request();

        return static::create([
            // Null once the actor's own row is gone - deleting yourself fires this
            // very event, and a foreign key to a deleted row would reject the line
            // that records the deletion. The name/email snapshot below is what
            // keeps it readable.
            'user_id' => $user?->exists ? $user->getKey() : null,
            'actor_name' => $user?->name,
            'actor_email' => $user?->email,
            'event' => $event,
            'subject' => $subject ? Str::limit($subject, 255, '') : null,
            'auditable_type' => $auditable ? $auditable::class : null,
            'auditable_id' => $auditable?->getKey(),
            'changes' => $changes ?: null,
            'ip_address' => $request?->ip(),
            'user_agent' => Str::limit((string) $request?->userAgent(), 512, ''),
            'created_at' => now(),
        ]);
    }

    /** Replaces the value of any sensitive field with a marker, keeping the key. */
    public static function redact(array $attributes): array
    {
        foreach ($attributes as $field => $value) {
            if (in_array($field, static::REDACTED_FIELDS, true)) {
                $attributes[$field] = '[disensor]';
            }
        }

        return $attributes;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The actor as stored at the time, so a deleted account still reads correctly. */
    public function actorLabel(): string
    {
        return $this->actor_name ?: ($this->actor_email ?: 'Sistem');
    }

    /** '{page}.{verb}' - the page half, used to colour and group the list. */
    public function eventGroup(): string
    {
        return Str::before($this->event, '.');
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        if ($term === '') {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q
            ->where('event', 'like', "%{$term}%")
            ->orWhere('subject', 'like', "%{$term}%")
            ->orWhere('actor_name', 'like', "%{$term}%")
            ->orWhere('actor_email', 'like', "%{$term}%")
            ->orWhere('ip_address', 'like', "%{$term}%"));
    }
}
