<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable forensic detail of one PageChange (1:1). The payload is gzipped JSON
 * (schema v1); read it through payload(), never the raw column. Rows are written
 * once and never updated; they disappear with their event via the FK cascade.
 */
class PageChangeForensic extends Model
{
    use HasFactory;

    public const SCHEMA_VERSION = 1;

    protected $table = 'page_change_forensics';

    protected $fillable = [
        'page_change_id',
        'payload_gz',
        'payload_bytes',
        'item_count',
        'undetailed_count',
        'schema_version',
    ];

    // Blob never belongs in JSON responses or Livewire payloads.
    protected $hidden = ['payload_gz'];

    protected function casts(): array
    {
        return [
            'payload_bytes' => 'integer',
            'item_count' => 'integer',
            'undetailed_count' => 'integer',
            'schema_version' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Evidence is write-once: cancel every model update.
        static::updating(fn () => false);
    }

    public function pageChange(): BelongsTo
    {
        return $this->belongsTo(PageChange::class);
    }

    /** The decoded payload, or null when the blob is corrupt or of an unknown schema version. */
    public function payload(): ?array
    {
        if (blank($this->payload_gz)) {
            return null;
        }

        $json = @gzdecode($this->payload_gz);

        if ($json === false) {
            return null;
        }

        $payload = json_decode($json, true);

        if (! is_array($payload) || ($payload['v'] ?? null) !== self::SCHEMA_VERSION) {
            return null;
        }

        return $payload;
    }
}
