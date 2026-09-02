<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebsiteCheck extends Model
{
    use HasUuid;

    public $timestamps = false;

    protected $fillable = [
        'website_id',
        'is_up',
        'status_code',
        'response_time_ms',
        'content_hash',
        'content_length',
        'error_message',
        'checked_at',
    ];

    protected function casts(): array
    {
        return [
            'is_up' => 'boolean',
            'checked_at' => 'datetime',
        ];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }
}
