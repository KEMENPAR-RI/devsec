<?php

namespace App\Services\Monitoring;

use App\Models\Incident;
use App\Models\Website;
use App\Models\WebsiteCheck;
use App\Services\AppSettings;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Data for the public TV wallboard. Public page: never add error_message or other
 * detail that can leak internal hosts/IPs.
 */
class TvBoard
{
    public function build(): array
    {
        // Public page polled by every screen/visitor: share one result, never staler than the poll interval.
        $ttl = min(app(AppSettings::class)->tvPollSeconds(), 60);

        return Cache::remember('tv-board', $ttl, fn () => $this->compute());
    }

    protected function compute(): array
    {
        $websites = Website::active()->with([
            'latestCheck',
            'incidents' => fn ($q) => $q->where('type', 'downtime')->open()->latest('detected_at'),
        ])->get();

        $offlineIds = Website::offlineIds($websites);
        $attackedIds = Website::attackedIds($websites);
        $maintenanceIds = Website::maintenanceIds($websites);
        $downtimes = $this->downtimes($websites->modelKeys());
        $upSites = $websites->filter(fn ($site) => $site->last_status === 'up');
        // First check is only the up-since fallback for up sites that never had a downtime.
        $firstChecks = $this->firstChecks($upSites
            ->filter(fn ($site) => ! isset($downtimes[$site->id]))
            ->values()->modelKeys());
        // Latest downtime has no usable resolved_at (open, null or older than detection): use the first up check after it.
        $recoveries = $this->recoveries($upSites
            ->filter(fn ($site) => isset($downtimes[$site->id]) && ! $this->recoveredAfterDetection($downtimes[$site->id]))
            ->values()->modelKeys());

        $masalah = [];
        $online = [];
        $internal = [];
        $mati = [];
        $latestEvent = null;

        foreach ($websites as $site) {
            $key = $site->statusKey(
                isset($offlineIds[$site->id]),
                isset($attackedIds[$site->id]),
                isset($maintenanceIds[$site->id]),
            );

            $check = $site->latestCheck;
            // When the current status started, so an ongoing outage keeps a stable timestamp across rechecks.
            $incidentAt = $site->incidents->first()?->detected_at;
            $since = $incidentAt ?? $check?->checked_at;
            // Public view does not reveal attack suspicion: shown as plain down.
            $publicKey = $key === 'attack' ? 'down' : $key;
            $config = Website::statusConfig($publicKey);
            $lastDown = $downtimes[$site->id]['detected'] ?? null;
            $upSince = null;
            if ($key === 'up') {
                $upSince = isset($downtimes[$site->id])
                    ? ($this->recoveredAfterDetection($downtimes[$site->id])
                        ? $downtimes[$site->id]['resolved']
                        : ($recoveries[$site->id] ?? $lastDown))
                    : ($firstChecks[$site->id] ?? null);
            }
            $row = [
                'name' => $site->name,
                'host' => $site->is_internal_only ? null : parse_url($site->url, PHP_URL_HOST),
                'status' => $publicKey,
                'status_label' => $config['label'],
                'dot' => $config['dot'],
                'card' => $config['card'],
                'occurred_at_ts' => $since?->timestamp ?? 0,
                'occurred_at' => $since?->diffForHumans() ?? '-',
                'up_since_ts' => $upSince?->timestamp ?? 0,
                'up_for' => $upSince ? $this->upFor(max(0, now()->timestamp - $upSince->timestamp)) : '',
                'last_down_at' => $lastDown?->translatedFormat('d M Y, H:i') ?? 'Belum pernah',
                'priority' => (bool) $site->is_priority,
            ];

            if ($key === 'up') {
                $online[] = $row;
            } elseif (in_array($key, ['down', 'attack'], true)) {
                $masalah[] = $row;
                // Only an open incident is an event; the check-time fallback moves every check and would re-fire the toast.
                if ($incidentAt && $row['occurred_at_ts'] > ($latestEvent['occurred_at_ts'] ?? -1)) {
                    $latestEvent = $row;
                }
            } elseif ($key === 'internal') {
                $internal[] = $row;
            } elseif (in_array($key, ['offline', 'unknown'], true)) {
                $mati[] = $row;
            } else {
                $online[] = $row;
            }
        }

        usort($masalah, fn ($a, $b) => $b['occurred_at_ts'] <=> $a['occurred_at_ts']);
        usort($online, fn ($a, $b) => strcmp($a['name'], $b['name']));
        usort($internal, fn ($a, $b) => strcmp($a['name'], $b['name']));
        usort($mati, fn ($a, $b) => $b['occurred_at_ts'] <=> $a['occurred_at_ts']);

        // Priority sites first; status-group order (problems on top) is kept within each half.
        $kartu = collect([$masalah, $online, $internal, $mati])->flatten(1)
            ->partition('priority')->flatten(1)
            // The flag only drives ordering and is not exposed on the public board.
            ->map(fn ($row) => Arr::except($row, 'priority'))->values()->all();

        return [
            'diperbarui' => now()->translatedFormat('d M Y, H:i:s'),
            'latest_event_at' => $latestEvent['occurred_at_ts'] ?? null,
            'latest_event' => $latestEvent ? [
                'name' => $latestEvent['name'],
                'pesan' => $latestEvent['status_label'] ?? 'Bermasalah',
                'status' => $latestEvent['status'],
            ] : null,
            'kartu' => $kartu,
        ];
    }

    /**
     * Latest downtime detection and recovery per site, in one grouped query. Timestamps only.
     *
     * @param  list<int>  $ids
     * @return array<int, array{detected: ?Carbon, resolved: ?Carbon}>
     */
    protected function downtimes(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = Incident::query()
            ->where('type', 'downtime')
            ->whereIn('website_id', $ids)
            ->groupBy('website_id')
            ->selectRaw('website_id, MAX(detected_at) AS last_detected, MAX(resolved_at) AS last_resolved')
            ->toBase()
            ->get();

        $downtimes = [];
        foreach ($rows as $row) {
            $downtimes[$row->website_id] = [
                'detected' => $row->last_detected ? Carbon::parse($row->last_detected) : null,
                'resolved' => $row->last_resolved ? Carbon::parse($row->last_resolved) : null,
            ];
        }

        return $downtimes;
    }

    /** Mirrors Alpine duration(): two largest units from the first non-zero one, e.g. '3 hari 4 jam'. */
    protected function upFor(int $seconds): string
    {
        $parts = [[intdiv($seconds, 86400), 'hari'], [intdiv($seconds % 86400, 3600), 'jam'], [intdiv($seconds % 3600, 60), 'mnt']];
        foreach ($parts as $i => [$n]) {
            if ($n > 0) {
                $top = array_filter(array_slice($parts, $i, 2), fn ($p) => $p[0] > 0);

                return implode(' ', array_map(fn ($p) => "{$p[0]} {$p[1]}", $top));
            }
        }

        return '< 1 mnt';
    }

    /**
     * MAX(detected_at) and MAX(resolved_at) may come from different incidents: resolved only counts if not older.
     *
     * @param  array{detected: ?Carbon, resolved: ?Carbon}  $downtime
     */
    protected function recoveredAfterDetection(array $downtime): bool
    {
        return $downtime['resolved'] !== null
            && ($downtime['detected'] === null || $downtime['resolved']->gte($downtime['detected']));
    }

    /**
     * First up check after the latest downtime detection per site, in one grouped query.
     *
     * @param  list<int>  $ids
     * @return array<int, Carbon>
     */
    protected function recoveries(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $checks = (new WebsiteCheck)->getTable();
        $lastDetected = Incident::query()
            ->where('type', 'downtime')
            ->whereIn('website_id', $ids)
            ->groupBy('website_id')
            ->selectRaw('website_id, MAX(detected_at) AS last_detected')
            ->toBase();

        return WebsiteCheck::query()
            ->joinSub($lastDetected, 'd', 'd.website_id', '=', "{$checks}.website_id")
            ->where("{$checks}.is_up", true)
            ->whereColumn("{$checks}.checked_at", '>', 'd.last_detected')
            ->groupBy("{$checks}.website_id")
            ->selectRaw("{$checks}.website_id AS website_id, MIN({$checks}.checked_at) AS recovered_at")
            ->toBase()
            ->get()
            ->mapWithKeys(fn ($row) => [$row->website_id => Carbon::parse($row->recovered_at)])
            ->all();
    }

    /**
     * First ever check time per site, in one grouped query.
     *
     * @param  list<int>  $ids
     * @return array<int, Carbon>
     */
    protected function firstChecks(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return WebsiteCheck::query()
            ->whereIn('website_id', $ids)
            ->groupBy('website_id')
            ->selectRaw('website_id, MIN(checked_at) AS first_checked')
            ->toBase()
            ->get()
            ->mapWithKeys(fn ($row) => [$row->website_id => Carbon::parse($row->first_checked)])
            ->all();
    }
}
