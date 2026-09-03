<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Support\AiText;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class JudolDetection extends Model
{
    use HasUuid;

    /** Characters of surrounding context shown on each side of a matched keyword. */
    protected const EXCERPT_CONTEXT = 90;

    /** Excerpts are capped so one page stuffed with 40 keywords stays readable. */
    protected const MAX_EXCERPTS = 6;

    protected $fillable = [
        'website_id',
        'incident_id',
        'url',
        'matched_keywords',
        'linked_domains',
        'snippet',
        'content_snapshot',
        'content_hash',
        'content_changes',
        'ai_verdict',
        'ai_confidence',
        'ai_reasoning',
        'ai_checked_at',
        'page_last_modified_at',
        'status',
        'detected_at',
    ];

    protected function casts(): array
    {
        return [
            'matched_keywords' => 'array',
            'linked_domains' => 'array',
            'content_changes' => 'array',
            'ai_confidence' => 'float',
            'ai_checked_at' => 'datetime',
            'detected_at' => 'datetime',
            'page_last_modified_at' => 'datetime',
        ];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    /**
     * How this finding should be labelled with respect to the AI classifier.
     *
     * The classifier only ever vets what the keyword filter found, so this describes
     * confidence in the finding, not which system found it - see the migration that
     * added ai_verdict.
     *
     * @return array{label: string, tone: string, detail: ?string}
     */
    public function aiBadge(): array
    {
        // "keyakinan 95%", not "confidence 0.95": this is a tooltip the operator
        // reads, and it sits beside the panel that already phrases it that way.
        //
        // Flattened to one line because ai_reasoning is now a bulleted findings
        // list, and a title="" attribute renders its newlines inconsistently across
        // browsers - the panel below is where that list is meant to be read.
        $detail = filled($this->ai_reasoning)
            ? AiText::inline($this->ai_reasoning).' (keyakinan '.number_format((float) $this->ai_confidence * 100).'%)'
            : null;

        return match ($this->ai_verdict) {
            'confirmed' => ['label' => 'Terverifikasi AI', 'tone' => 'accent', 'detail' => $detail],
            'disputed' => ['label' => 'AI ragu', 'tone' => 'amber', 'detail' => $detail],
            'unavailable' => ['label' => 'AI gagal dicek', 'tone' => 'zinc', 'detail' => $detail],
            default => ['label' => 'Keyword saja', 'tone' => 'zinc', 'detail' => 'Klasifikasi AI nonaktif saat halaman ini dipindai.'],
        };
    }

    /**
     * The actual gambling passages on the page: one context window around each
     * matched keyword, pulled from the stored snapshot.
     *
     * On an injected page this is, in practice, "what was changed" - the injection
     * IS this text - and it is the closest thing available on a first detection,
     * where there is no earlier version to diff against.
     *
     * @return array<int, string>
     */
    public function matchedExcerpts(): array
    {
        $text = $this->content_snapshot;

        if (blank($text)) {
            // Rows created before snapshots existed still have the legacy excerpt.
            return array_values(array_filter([$this->snippet]));
        }

        $excerpts = [];

        foreach ($this->matched_keywords ?? [] as $keyword) {
            $position = mb_stripos($text, $keyword);

            if ($position === false) {
                continue;
            }

            $start = max(0, $position - self::EXCERPT_CONTEXT);
            $length = mb_strlen($keyword) + (self::EXCERPT_CONTEXT * 2);
            $excerpt = trim(mb_substr($text, $start, $length));

            // Spam copy clusters its keywords, so neighbouring matches would
            // otherwise yield near-identical windows.
            if (! $this->overlapsExisting($excerpt, $excerpts)) {
                $excerpts[] = ($start > 0 ? '...' : '').$excerpt.'...';
            }

            if (count($excerpts) >= self::MAX_EXCERPTS) {
                break;
            }
        }

        return $excerpts;
    }

    /**
     * Whether this window is already covered by one that was kept - two keywords a
     * few words apart produce windows sharing most of their text.
     *
     * @param  array<int, string>  $existing
     */
    protected function overlapsExisting(string $excerpt, array $existing): bool
    {
        $core = Str::of($excerpt)->squish()->lower()->limit(60, '')->value();

        if ($core === '') {
            return false;
        }

        foreach ($existing as $kept) {
            if (Str::of($kept)->squish()->lower()->contains($core)) {
                return true;
            }
        }

        return false;
    }
}
