<?php

namespace App\Http\Controllers;

use App\Models\Incident;
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
                'kind' => 'situs',
                'incident_title' => null,
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

        $incidents = Incident::open()
            ->with(['website' => fn ($q) => $q->with(['latestCheck'])])
            ->latest('detected_at')
            ->limit(50)
            ->get()
            ->map(function (Incident $incident) use ($offlineIds, $attackedIds, $maintenanceIds) {
                $site = $incident->website;
                $key = $site?->statusKey(
                    isset($offlineIds[$site?->id]),
                    isset($attackedIds[$site?->id]),
                    isset($maintenanceIds[$site?->id]),
                ) ?? 'unknown';
                $config = Website::statusConfig($key);

                return [
                    'name' => $site?->name ?? '-',
                    'url' => $site?->url ?? '-',
                    'status' => $key,
                    'status_label' => $config['label'],
                    'dot' => $config['dot'],
                    'is_up' => false,
                    'status_code' => $site?->latestCheck?->status_code,
                    'response_time_ms' => $site?->latestCheck?->response_time_ms,
                    'error_message' => $site?->latestCheck?->error_message,
                    'last_checked_at' => $site?->latestCheck?->checked_at?->diffForHumans(),
                    'occurred_at_ts' => $incident->detected_at?->timestamp ?? 0,
                    'occurred_at' => $incident->detected_at?->diffForHumans() ?? '-',
                    'kind' => 'insiden',
                    'incident_title' => $incident->title,
                ];
            })
            ->all();

        $masalah = array_merge($masalah, $incidents);

        $upConfig = Website::statusConfig('up');
        $downConfig = Website::statusConfig('down');
        $offlineConfig = Website::statusConfig('offline');
        $unknownConfig = Website::statusConfig('unknown');

        for ($i = 1; $i <= 20; $i++) {
            $masalah[] = [
                'name' => "Situs Down Dummy $i",
                'url' => "https://down-dummy$i.example.com",
                'status' => 'down',
                'status_label' => $downConfig['label'],
                'dot' => $downConfig['dot'],
                'is_up' => false,
                'status_code' => null,
                'response_time_ms' => null,
                'error_message' => 'Connection timed out',
                'last_checked_at' => "$i menit yang lalu",
                'occurred_at_ts' => now()->subMinutes($i)->timestamp,
                'occurred_at' => "$i menit yang lalu",
                'kind' => 'situs',
                'incident_title' => null,
            ];

            $masalah[] = [
                'name' => "Situs Dummy $i",
                'url' => "https://dummy$i.example.com",
                'status' => 'down',
                'status_label' => $downConfig['label'],
                'dot' => $downConfig['dot'],
                'is_up' => false,
                'status_code' => null,
                'response_time_ms' => null,
                'error_message' => null,
                'last_checked_at' => "$i menit yang lalu",
                'occurred_at_ts' => now()->subMinutes($i)->timestamp,
                'occurred_at' => "$i menit yang lalu",
                'kind' => 'insiden',
                'incident_title' => "Insiden Dummy $i",
            ];

            $online[] = [
                'name' => "Situs Online Dummy $i",
                'url' => "https://online-dummy$i.example.com",
                'status' => 'up',
                'status_label' => $upConfig['label'],
                'dot' => $upConfig['dot'],
                'is_up' => true,
                'status_code' => 200,
                'response_time_ms' => 90 + $i,
                'error_message' => null,
                'last_checked_at' => "$i menit yang lalu",
                'occurred_at_ts' => now()->subMinutes($i)->timestamp,
                'occurred_at' => "$i menit yang lalu",
                'kind' => 'situs',
                'incident_title' => null,
            ];

            $mati[] = [
                'name' => "Situs Mati Dummy $i",
                'url' => "https://mati-dummy$i.example.com",
                'status' => 'offline',
                'status_label' => $offlineConfig['label'],
                'dot' => $offlineConfig['dot'],
                'is_up' => false,
                'status_code' => null,
                'response_time_ms' => null,
                'error_message' => 'Down lebih dari 24 jam',
                'last_checked_at' => "$i jam yang lalu",
                'occurred_at_ts' => now()->subHours($i)->timestamp,
                'occurred_at' => "$i jam yang lalu",
                'kind' => 'situs',
                'incident_title' => null,
            ];

            $mati[] = [
                'name' => "Situs Unknown Dummy $i",
                'url' => "https://unknown-dummy$i.example.com",
                'status' => 'unknown',
                'status_label' => $unknownConfig['label'],
                'dot' => $unknownConfig['dot'],
                'is_up' => false,
                'status_code' => null,
                'response_time_ms' => null,
                'error_message' => null,
                'last_checked_at' => 'belum dicek',
                'occurred_at_ts' => 0,
                'occurred_at' => 'belum dicek',
                'kind' => 'situs',
                'incident_title' => null,
            ];
        }

        usort($masalah, fn ($a, $b) => $b['occurred_at_ts'] <=> $a['occurred_at_ts']);
        usort($online, fn ($a, $b) => strcmp($a['name'], $b['name']));
        usort($mati, fn ($a, $b) => $b['occurred_at_ts'] <=> $a['occurred_at_ts']);

        $kartu = array_merge($masalah, $online, $mati);

        $latestEvent = collect($masalah)->first(fn ($row) => ! str_contains((string) ($row['url'] ?? ''), 'dummy'));

        return response()->json([
            'diperbarui' => now()->translatedFormat('d M Y, H:i:s'),
            'latest_event_at' => $latestEvent['occurred_at_ts'] ?? null,
            'latest_event' => $latestEvent ? [
                'name' => $latestEvent['name'],
                'pesan' => $latestEvent['kind'] === 'insiden'
                    ? ($latestEvent['incident_title'] ?? 'Insiden aktif')
                    : ($latestEvent['status_label'] ?? 'Bermasalah'),
                'status' => $latestEvent['status'],
            ] : null,
            'kartu' => $kartu,
        ]);
    }
}
