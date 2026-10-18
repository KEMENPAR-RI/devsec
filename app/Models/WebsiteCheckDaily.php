<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebsiteCheckDaily extends Model
{
    protected $table = 'website_check_daily';

    protected $fillable = [
        'website_id',
        'day',
        'total_checks',
        'up_checks',
        'avg_response_ms',
        'timed_checks',
        'response_ms_sum',
    ];

    protected function casts(): array
    {
        return [
            'day' => 'date',
            'total_checks' => 'integer',
            'up_checks' => 'integer',
            'avg_response_ms' => 'integer',
            'timed_checks' => 'integer',
            'response_ms_sum' => 'integer',
        ];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }
}
