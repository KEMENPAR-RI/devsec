<?php

namespace App\Http\Controllers;

use App\Models\Website;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

/**
 * Read-only wallboard for a TV in the NOC.
 */
class TvDisplayController extends Controller
{
    public function show(): View
    {
        return view('tv.index', ['token' => request()->query('token', '')]);
    }

    public function data(): JsonResponse
    {
        $websites = Website::withCount(['checks' => fn ($q) => $q->where('checked_at', '>=', now()->subDay())])
            ->with(['latestCheck'])
            ->get();

        $offlineIds = Website::offlineIds($websites);
        $attackedIds = Website::attackedIds($websites);
        $maintenanceIds = Website::maintenanceIds($websites);

        $masalah = [];
        $online = [];
        $mati = [];

        foreach ($websites as $site) {
            $key = $site->statusKey(
                isset($offlineIds[$site->id]),
                isset($attackedIds[$site->id]),
                isset($maintenanceIds[$site->id]),
            );

            $check = $site->latestCheck;
            $config = Website::statusConfig($key);
            $row = [
                'name' => $site->name,
                'url' => $site->url,
                'status' => $key,
                'status_label' => $config['label'],
                'dot' => $config['dot'],
                'is_up' => $check?->is_up,
                'status_code' => $check?->status_code,
                'response_time_ms' => $check?->response_time_ms,
                'error_message' => $check?->error_message,
                'last_checked_at' => $check?->checked_at?->diffForHumans(),
                'occurred_at_ts' => $check?->checked_at?->timestamp ?? 0,
                'occurred_at' => $check?->checked_at?->diffForHumans() ?? '-',
            ];

            if ($key === 'up') {
                $online[] = $row;
            } elseif (in_array($key, ['down', 'attack', 'internal'], true)) {
                $masalah[] = $row;
            } elseif (in_array($key, ['offline', 'unknown'], true)) {
                $mati[] = $row;
            } else {
                $online[] = $row;
            }
        }

        usort($masalah, fn ($a, $b) => $b['occurred_at_ts'] <=> $a['occurred_at_ts']);
        usort($online, fn ($a, $b) => strcmp($a['name'], $b['name']));
        usort($mati, fn ($a, $b) => $b['occurred_at_ts'] <=> $a['occurred_at_ts']);

        $kartu = array_merge($masalah, $online, $mati);

        $latestEvent = $masalah[0] ?? null;

        return response()->json([
            'diperbarui' => now()->translatedFormat('d M Y, H:i:s'),
            'latest_event_at' => $latestEvent['occurred_at_ts'] ?? null,
            'latest_event' => $latestEvent ? [
                'name' => $latestEvent['name'],
                'pesan' => $latestEvent['status_label'] ?? 'Bermasalah',
                'status' => $latestEvent['status'],
            ] : null,
            'kartu' => $kartu,
        ]);
    }
}
