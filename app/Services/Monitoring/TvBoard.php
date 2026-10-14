<?php

namespace App\Services\Monitoring;

use App\Models\Website;
use App\Models\WebsiteCheck;
use App\Services\AppSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Data for the public TV wallboard. Public page: never add error_message or other
 * detail that can leak internal hosts/IPs.
 */
class TvBoard
{
    protected const HISTORY_SIZE = 24;

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
        $history = $this->history($websites->modelKeys());

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
            $row = [
                'name' => $site->name,
                'host' => $site->is_internal_only ? null : parse_url($site->url, PHP_URL_HOST),
                'status' => $publicKey,
                'status_label' => $config['label'],
                'dot' => $config['dot'],
                'card' => $config['card'],
                'text' => $config['text'],
                'occurred_at_ts' => $since?->timestamp ?? 0,
                'occurred_at' => $since?->diffForHumans() ?? '-',
                'history' => $history[$site->id] ?? [],
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

        $kartu = array_merge($masalah, $online, $internal, $mati);

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
     * Last HISTORY_SIZE is_up values per site, oldest first, in one query (window function:
     * MariaDB 10.2+/MySQL 8+/SQLite 3.25+). Booleans only: this feeds a public page.
     *
     * @param  list<int>  $ids
     * @return array<int, list<bool>>
     */
    protected function history(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $ranked = WebsiteCheck::query()
            ->select(['website_id', 'is_up', 'checked_at', 'id'])
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY website_id ORDER BY checked_at DESC, id DESC) AS rn')
            ->whereIn('website_id', $ids);

        $rows = DB::query()
            ->fromSub($ranked, 'ranked')
            ->where('rn', '<=', self::HISTORY_SIZE)
            ->orderBy('website_id')
            ->orderBy('checked_at')
            ->orderBy('id')
            ->get(['website_id', 'is_up']);

        $history = [];
        foreach ($rows as $row) {
            $history[$row->website_id][] = (bool) $row->is_up;
        }

        return $history;
    }
}
