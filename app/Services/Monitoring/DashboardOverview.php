<?php

namespace App\Services\Monitoring;

use App\Models\Incident;
use App\Models\Website;
use App\Models\WebsiteCheck;
use App\Support\Dates;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Data for the dashboard overview. Cached values are global (never user-specific).
 */
class DashboardOverview
{
    public const DEFAULT_RANGE = '24h';

    /** range => [bucket seconds, bucket count, cache ttl seconds, label format] */
    public const RANGES = [
        '1h' => [300, 12, 15, 'H:i'],
        '24h' => [3600, 24, 60, 'H:i'],
        '7d' => [21600, 28, 300, 'D H:i'],
        '30d' => [86400, 30, 900, 'd M'],
    ];

    public const HISTORY_SIZE = 24;

    /** Fresh window of the shared real-time snapshot (seconds); served stale for as long again. */
    public const REALTIME_TTL = 15;

    /** Upper bound on a background refresh's lock, so a crashed refresh can't block the key. */
    protected const REFRESH_LOCK_SECONDS = 30;

    public static function normalizeRange(?string $range): string
    {
        return isset(self::RANGES[$range]) ? $range : self::DEFAULT_RANGE;
    }

    /**
     * Every site plus its display status, from one set of bulk lookups.
     *
     * @return array{websites: Collection<int, Website>, statuses: array<int, string>}
     */
    public function sites(): array
    {
        // Same order as the admin list (Website::scopeOrderForList()); liveSites() keeps it.
        $websites = Website::orderForList()->get();

        $offline = Website::offlineIds($websites);
        $attacked = Website::attackedIds($websites);
        $maintenance = Website::maintenanceIds($websites);

        $statuses = $websites->mapWithKeys(fn (Website $site) => [$site->id => $site->statusKey(
            isset($offline[$site->id]),
            isset($attacked[$site->id]),
            isset($maintenance[$site->id]),
        )])->all();

        return ['websites' => $websites, 'statuses' => $statuses];
    }

    /**
     * Summary, live list and load counts from one site load, shared by every viewer.
     * Holds no user or permission data, so one global key is safe.
     *
     * @return array{summary: array, live_sites: list<array>, load_counts: array<string, int>}
     */
    public function realtime(): array
    {
        return $this->flexible('dashboard:realtime', self::REALTIME_TTL, function () {
            $sites = $this->sites();
            $loadLevels = Website::loadLevels($sites['websites']);

            return [
                'summary' => $this->summary($sites['statuses']),
                'live_sites' => $this->liveSites($sites['websites'], $sites['statuses'], $loadLevels),
                'load_counts' => $this->loadCounts($loadLevels),
            ];
        });
    }

    /** Stale-while-revalidate: one locked background refresh instead of a stampede on expiry. */
    protected function flexible(string $key, int $ttl, callable $callback): array
    {
        return Cache::flexible($key, [$ttl, $ttl * 2], $callback, ['seconds' => self::REFRESH_LOCK_SECONDS]);
    }

    /**
     * @param  array<int, string>  $statuses
     * @return array{total: int, online: int, problem: int, offline: int, breakdown: array<string, int>}
     */
    public function summary(array $statuses): array
    {
        $breakdown = array_fill_keys(['up', 'warning', 'maintenance', 'down', 'attack', 'offline', 'internal', 'unknown'], 0);
        foreach ($statuses as $key) {
            // Unknown keys fall back like statusConfig() does, so the partition always sums to total.
            $breakdown[isset($breakdown[$key]) ? $key : 'unknown']++;
        }

        return [
            'total' => count($statuses),
            // Warning still answers (slowly), so it stays in the online partition.
            'online' => $breakdown['up'] + $breakdown['warning'] + $breakdown['maintenance'],
            'problem' => $breakdown['down'] + $breakdown['attack'],
            'offline' => $breakdown['offline'] + $breakdown['internal'] + $breakdown['unknown'],
            'breakdown' => $breakdown,
        ];
    }

    /**
     * @param  Collection<int, Website>  $websites
     * @param  array<int, string>  $statuses
     * @param  array<int, array{level: string, response_time_ms: int|null}>  $loadLevels
     * @return list<array{uuid: string, name: string, url: string, status_key: string, status_label: string, load_level: string, history: list<bool>, last_checked_at: ?Carbon}>
     */
    public function liveSites(Collection $websites, array $statuses, array $loadLevels): array
    {
        $history = WebsiteCheck::historyFor($websites->modelKeys(), self::HISTORY_SIZE);

        return $websites->map(function (Website $site) use ($statuses, $loadLevels, $history) {
            $key = $statuses[$site->id] ?? 'unknown';

            return [
                'uuid' => $site->uuid,
                'name' => $site->name,
                'url' => $site->url,
                'status_key' => $key,
                'status_label' => Website::statusConfig($key)['label'],
                'load_level' => $loadLevels[$site->id]['level'] ?? 'unknown',
                'history' => $history[$site->id] ?? [],
                'last_checked_at' => $site->last_checked_at,
            ];
        })->all();
    }

    /**
     * @param  array<int, array{level: string, response_time_ms: int|null}>  $loadLevels
     * @return array<string, int>
     */
    public function loadCounts(array $loadLevels): array
    {
        return collect($loadLevels)->countBy(fn (array $row) => $row['level'])->all();
    }

    /**
     * Bucketed response time + uptime for a range, plus the range-wide totals.
     *
     * @return array{series: list<array{label: string, ms: ?int, uptime: ?float, up: ?bool}>, uptime: ?float, avg_ms: ?int}
     */
    public function performance(string $range, ?int $websiteId = null): array
    {
        $range = self::normalizeRange($range);
        $timezone = Dates::timezone();
        $key = 'dashboard:perf:'.$range.':'.($websiteId ?? 'all').':'.$timezone;

        return $this->flexible($key, self::RANGES[$range][2], fn () => $this->computePerformance($range, $websiteId, $timezone));
    }

    protected function computePerformance(string $range, ?int $websiteId, string $timezone): array
    {
        [$size, $count, , $format] = self::RANGES[$range];

        // Buckets align to the display timezone's wall clock; current offset (no DST in WIB/WITA/WIT).
        $displayOffset = Carbon::now($timezone)->getOffset();
        // The column holds storage-zone wall clock, so the SQL epoch is shifted by the storage offset.
        $shift = $displayOffset - Carbon::now(config('app.timezone'))->getOffset();

        $current = intdiv(now()->timestamp + $displayOffset, $size);
        $first = $current - $count + 1;
        $start = Carbon::createFromTimestamp($first * $size - $displayOffset, config('app.timezone'));

        $rows = WebsiteCheck::query()
            ->selectRaw($this->bucketSql($shift, $size).' AS bucket')
            ->selectRaw('SUM(CASE WHEN '.WebsiteCheck::UP_SQL.' THEN 1 ELSE 0 END) AS up_count')
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('SUM(response_time_ms) AS ms_sum')
            ->selectRaw('COUNT(response_time_ms) AS ms_count')
            ->where('checked_at', '>=', $start)
            ->when($websiteId, fn ($q) => $q->where('website_id', $websiteId))
            ->groupBy('bucket')
            ->toBase()
            ->get()
            ->keyBy(fn ($row) => (int) $row->bucket);

        $series = [];
        $up = $total = $msSum = $msCount = 0;

        for ($bucket = $first; $bucket <= $current; $bucket++) {
            $row = $rows->get($bucket);
            $label = Carbon::createFromTimestamp($bucket * $size - $displayOffset, $timezone)->translatedFormat($format);

            if (! $row || (int) $row->total === 0) {
                $series[] = ['label' => $label, 'ms' => null, 'uptime' => null, 'up' => null];

                continue;
            }

            $up += (int) $row->up_count;
            $total += (int) $row->total;
            $msSum += (int) $row->ms_sum;
            $msCount += (int) $row->ms_count;

            $series[] = [
                'label' => $label,
                'ms' => (int) $row->ms_count > 0 ? (int) round($row->ms_sum / $row->ms_count) : null,
                'uptime' => round($row->up_count / $row->total * 100, 2),
                // Any failure in the bucket marks it down; the average would hide one site's outage.
                'up' => (int) $row->up_count === (int) $row->total,
            ];
        }

        return [
            'series' => $series,
            'uptime' => $total > 0 ? round($up / $total * 100, 2) : null,
            'avg_ms' => $msCount > 0 ? (int) round($msSum / $msCount) : null,
        ];
    }

    /** Integer bucket index of checked_at; the only driver-specific SQL here. */
    protected function bucketSql(int $shift, int $size): string
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            // Integer division truncates, which equals FLOOR for positive epochs.
            return "((CAST(strftime('%s', checked_at) AS INTEGER) + {$shift}) / {$size})";
        }

        // TIMESTAMPDIFF ignores the session time_zone, unlike UNIX_TIMESTAMP().
        return "FLOOR((TIMESTAMPDIFF(SECOND, '1970-01-01 00:00:00', checked_at) + {$shift}) / {$size})";
    }

    /** @return Collection<int, Incident> */
    public function openIncidents(int $limit = 6): Collection
    {
        return Incident::open()
            ->with('website:id,uuid,name')
            ->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'warning' THEN 1 WHEN 'info' THEN 2 ELSE 3 END")
            ->latest('detected_at')
            ->limit($limit)
            ->get();
    }

    public function openIncidentsCount(): int
    {
        return Incident::open()->count();
    }

    /** @return Collection<int, Incident> */
    public function recentIncidents(int $limit = 6): Collection
    {
        return Incident::with('website:id,uuid,name')->latest('detected_at')->limit($limit)->get();
    }
}
