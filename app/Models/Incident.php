<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

class Incident extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = [
        'website_id',
        'type',
        'severity',
        'title',
        'description',
        'meta',
        'ai_analysis',
        'ai_analyzed_at',
        'status',
        'detected_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'detected_at' => 'datetime',
            'resolved_at' => 'datetime',
            'ai_analyzed_at' => 'datetime',
        ];
    }

    /** @var array{from: ?Carbon, to: Carbon}|null memoized outageWindow() result */
    protected ?array $outageWindow = null;

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function judolDetection(): HasOne
    {
        return $this->hasOne(JudolDetection::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 'open');
    }

    public function resolve(): void
    {
        $this->update(['status' => 'resolved', 'resolved_at' => now()]);
    }

    /**
     * When the outage actually began, as bounded by our own check history: the last
     * check that still succeeded, and the first one that failed after it. detected_at
     * only says when the failure streak crossed down_threshold_failures - the real
     * start is somewhere in this window, and its width is just the check interval.
     *
     * Only meaningful for downtime incidents; null for every other type (and when
     * there is no check history to bound it with).
     *
     * @return array{from: ?Carbon, to: Carbon}|null
     */
    public function outageWindow(): ?array
    {
        if ($this->type !== 'downtime') {
            return null;
        }

        return $this->outageWindow ??= (function () {
            $lastUp = $this->website->checks()
                ->where('is_up', true)
                ->where('checked_at', '<=', $this->detected_at)
                ->latest('checked_at')
                ->first();

            $firstDown = $this->website->checks()
                ->where('is_up', false)
                ->where('checked_at', '<=', $this->detected_at)
                ->when($lastUp, fn ($q) => $q->where('checked_at', '>', $lastUp->checked_at))
                ->oldest('checked_at')
                ->first();

            return $firstDown
                ? ['from' => $lastUp?->checked_at, 'to' => $firstDown->checked_at]
                : null;
        })();
    }

    /**
     * outageWindow() rendered for display: "sekitar 14:03 - 14:05 (jeda cek 2 menit)".
     * Without a preceding successful check the start is unknown, so only the first
     * failed check is stated.
     */
    protected function outageWindowLabel(): ?string
    {
        $window = $this->outageWindow();

        if (! $window) {
            return null;
        }

        if (! $window['from']) {
            return 'Gagal pertama kali tercatat '.$window['to']->translatedFormat('d M Y, H:i');
        }

        $gap = $window['from']->diffForHumans($window['to'], ['syntax' => CarbonInterface::DIFF_ABSOLUTE, 'parts' => 1]);

        return $window['from']->translatedFormat('d M Y, H:i').' - '.$window['to']->translatedFormat('H:i')." (rentang {$gap})";
    }

    /**
     * Structured, human-readable detail lines derived from `meta`, tailored per
     * incident type - the raw data behind the incident (status code, error message,
     * response time vs. baseline, matched keywords, etc.) for the UI to display.
     *
     * @return array<string, string>
     */
    public function detailLines(): array
    {
        $meta = $this->meta ?? [];

        $lines = match ($this->type) {
            'downtime' => [
                'Mulai Antara' => $this->outageWindowLabel(),
                'Kode Status HTTP' => isset($meta['status_code']) ? "HTTP {$meta['status_code']}" : 'Tidak ada respons',
                'Pesan Error' => $meta['error_message'] ?? null,
                'Kegagalan Berturut-turut' => isset($meta['consecutive_failures']) ? "{$meta['consecutive_failures']}x" : null,
                // Deliberately labelled "Dugaan": this is inferred from the shape of
                // our own checks, not observed on the server. See App\Services\Monitoring\OutageCause.
                'Dugaan Penyebab' => $meta['likely_cause'] ?? null,
                'Dasar Dugaan' => $meta['cause_evidence'] ?? null,
                'Catatan' => $meta['cause_caveat'] ?? null,
            ],
            'attack' => [
                'Indikasi' => match ($meta['reason'] ?? null) {
                    'response_time_spike' => 'Lonjakan response time',
                    'content_changed' => 'Perubahan konten drastis',
                    default => null,
                },
                'Response Time' => isset($meta['response_time_ms']) ? number_format($meta['response_time_ms']).' ms' : null,
                'Baseline Normal' => isset($meta['baseline_ms']) ? number_format($meta['baseline_ms']).' ms' : null,
                'Ukuran Sebelumnya' => isset($meta['previous_length']) ? number_format($meta['previous_length']).' byte' : null,
                'Ukuran Sekarang' => isset($meta['current_length']) ? number_format($meta['current_length']).' byte' : null,
            ],
            'ssl' => [
                'Kedaluwarsa Pada' => isset($meta['expires_at']) ? Carbon::parse($meta['expires_at'])->translatedFormat('d F Y') : null,
            ],
            'judol' => [
                'URL Halaman' => $meta['url'] ?? null,
                'Kata Kunci Cocok' => isset($meta['matched_keywords']) ? implode(', ', $meta['matched_keywords']) : null,
                'Halaman Terakhir Diubah' => $this->judolDetection?->page_last_modified_at?->translatedFormat('d F Y, H:i'),
            ],
            default => [],
        };

        return array_filter($lines, fn ($value) => filled($value));
    }
}
