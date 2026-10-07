<?php

namespace App\Http\Controllers;

use App\Models\Incident;
use App\Models\Website;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

/**
 * Read-only wallboard for a TV in the NOC.
 *
 * The numbers come from the exact same queries Dashboard::render() uses -
 * same statusKey() tallies over the same eager-loaded website list - so a
 * site that shows red on the dashboard cannot read green on the TV.
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

        $online = 0;
        $down = 0;
        $allSites = [];
        $onlineSites = [];
        $downSites = [];

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
            ];

            $allSites[] = $row;

            if ($key === 'up') {
                $online++;
                $onlineSites[] = $row;
            } elseif (in_array($key, ['down', 'attack', 'offline', 'internal'], true)) {
                // Every non-online failure state collapses into the one "Down"
                // card - the TV is an at-a-glance view, its only question is
                // "is anything broken right now".
                $down++;
                $downSites[] = $row;
            }
        }

        $incidents = Incident::open()
            ->with(['website' => fn ($q) => $q->with(['latestCheck'])])
            ->latest('detected_at')
            ->limit(10)
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
                    'title' => $incident->title,
                    'severity' => $incident->severity,
                    'type' => $incident->type,
                    'website_name' => $site?->name,
                    'website_url' => $site?->url,
                    'status' => $key,
                    'status_label' => $config['label'],
                    'dot' => $config['dot'],
                    'detected_at' => $incident->detected_at?->diffForHumans(),
                ];
            });

        return response()->json([
            'total' => $websites->count(),
            'online' => $online,
            'down' => $down,
            'insiden' => Incident::open()->count(),
            'diperbarui' => now()->translatedFormat('d M Y, H:i'),
            'semua_situs' => $allSites,
            'situs_online' => $onlineSites,
            'situs_down' => $downSites,
            'insiden_aktif' => $incidents,
        ]);
    }
}
