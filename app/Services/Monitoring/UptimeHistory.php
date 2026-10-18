<?php

namespace App\Services\Monitoring;

use App\Models\Incident;
use App\Models\WebsiteCheck;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Per-site uptime history for the site detail page. Read-only, uncached.
 */
class UptimeHistory
{
    public const PERIODS = ['24h' => 1, '7d' => 7, '30d' => 30];

    /** @return array{'24h': ?float, '7d': ?float, '30d': ?float} null when a period has no checks */
    public function summary(int $websiteId): array
    {
        $now = now();
        $query = WebsiteCheck::query()
            ->where('website_id', $websiteId)
            ->where('checked_at', '>=', $now->copy()->subDays(max(self::PERIODS)))
            ->toBase();

        // One pass over the widest window; narrower periods are conditional sums.
        foreach (self::PERIODS as $days) {
            $since = $now->copy()->subDays($days);
            $query->selectRaw("SUM(CASE WHEN checked_at >= ? THEN 1 ELSE 0 END) AS total_{$days}", [$since])
                ->selectRaw("SUM(CASE WHEN checked_at >= ? AND is_up = 1 THEN 1 ELSE 0 END) AS up_{$days}", [$since]);
        }

        $row = $query->first();
        $summary = [];

        foreach (self::PERIODS as $key => $days) {
            $total = (int) ($row?->{"total_{$days}"} ?? 0);
            $summary[$key] = $total > 0 ? round((int) $row->{"up_{$days}"} / $total * 100, 2) : null;
        }

        return $summary;
    }

    /**
     * Downtime incidents detected in the last $days (plus any still open), newest first.
     *
     * @return list<array{uuid: string, started_at: Carbon, ended_at: ?Carbon, duration_seconds: int, ongoing: bool, cause: ?string, cause_detail: ?string, status_code: ?int}>
     */
    public function downtimes(int $websiteId, int $days = 90, int $limit = 20): array
    {
        $now = now();

        return $this->downtimeQuery($websiteId)
            ->where(fn ($q) => $q->open()->orWhere('detected_at', '>=', $now->copy()->subDays($days)))
            ->latest('detected_at')
            ->latest('id')
            ->limit($limit)
            ->get(['id', 'uuid', 'meta', 'detected_at', 'resolved_at', 'created_at'])
            ->map(function (Incident $incident) use ($now) {
                $meta = $incident->meta ?? [];
                $startedAt = $this->startedAt($incident);
                $endedAt = $incident->resolved_at;

                return [
                    'uuid' => $incident->uuid,
                    'started_at' => $startedAt,
                    'ended_at' => $endedAt,
                    'duration_seconds' => (int) max(0, $startedAt->diffInSeconds($endedAt ?? $now)),
                    'ongoing' => $endedAt === null,
                    // Machine key (timeout/dns/...); null on incidents opened before error_type existed.
                    'cause' => $meta['error_type'] ?? null,
                    // Rows from before the guard went generic may still name an internal IP.
                    'cause_detail' => Incident::redactBlocked($meta['error_message'] ?? $meta['likely_cause'] ?? null, $meta['error_type'] ?? null),
                    'status_code' => isset($meta['status_code']) ? (int) $meta['status_code'] : null,
                ];
            })
            ->values()
            ->all();
    }

    /** Total downtime seconds within the last $days, each incident clipped to the window. */
    public function downtimeSeconds(int $websiteId, int $days = 30): int
    {
        $now = now();
        $since = $now->copy()->subDays($days);

        return (int) $this->downtimeQuery($websiteId)
            ->where(fn ($q) => $q->whereNull('resolved_at')->orWhere('resolved_at', '>=', $since))
            ->get(['meta', 'detected_at', 'resolved_at', 'created_at'])
            ->sum(function (Incident $incident) use ($now, $since) {
                $start = $this->startedAt($incident)->max($since);
                $end = ($incident->resolved_at ?? $now)->min($now);

                return max(0, $start->diffInSeconds($end));
            });
    }

    protected function downtimeQuery(int $websiteId): Builder
    {
        return Incident::query()->where('website_id', $websiteId)->where('type', 'downtime');
    }

    /** First failed check of the streak when recorded, else when the incident row was created. */
    protected function startedAt(Incident $incident): Carbon
    {
        $firstFailedAt = $incident->meta['first_failed_at'] ?? null;

        return $firstFailedAt
            ? Carbon::parse($firstFailedAt)->setTimezone(config('app.timezone'))
            : ($incident->created_at ?? $incident->detected_at);
    }
}
