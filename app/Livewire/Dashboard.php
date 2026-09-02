<?php

namespace App\Livewire;

use App\Models\Incident;
use App\Models\JudolDetection;
use App\Models\Website;
use App\Models\WebsiteCheck;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class Dashboard extends Component
{
    /** The picked site's uuid - no real primary key reaches the browser. */
    public ?string $selectedWebsiteUuid = null;

    public function mount(): void
    {
        $this->authorize('dashboard.view');
    }

    /**
     * @param  Collection<int, Website>  $websites  passed in from
     *                                              render() so this and websiteStatuses() share one query instead of each
     *                                              scanning the websites table separately.
     */
    public function stats($websites): array
    {
        $openIncidents = Incident::open()->count();
        $newJudol = JudolDetection::where('status', 'new')->count();

        return [
            [
                'label' => 'Total Situs',
                'value' => (string) $websites->count(),
                'icon' => 'globe',
            ],
            [
                'label' => 'Online',
                'value' => (string) $websites->where('last_status', 'up')->count(),
                'icon' => 'check-circle',
                'tone' => 'up',
            ],
            [
                'label' => 'Down',
                'value' => (string) $websites->where('last_status', 'down')->count(),
                'icon' => 'warning-circle',
                'tone' => 'down',
            ],
            [
                'label' => 'Insiden Aktif',
                'value' => (string) $openIncidents,
                'icon' => 'bell-ringing',
                'tone' => $openIncidents > 0 ? 'down' : null,
            ],
            [
                'label' => 'Deteksi Judol Baru',
                'value' => (string) $newJudol,
                'icon' => 'shield-warning',
                'tone' => $newJudol > 0 ? 'down' : null,
            ],
        ];
    }

    /**
     * Overall uptime percentage across all websites, per day, for the last 7 days.
     * One aggregate query (grouped by day) instead of 7 separate range-scans - this
     * runs on every dashboard render, including every wire:poll tick, so it matters.
     */
    public function weeklyUptime(): array
    {
        $start = Carbon::today()->subDays(6)->startOfDay();

        $rows = WebsiteCheck::selectRaw('DATE(checked_at) as day, SUM(is_up) as up_count, COUNT(*) as total')
            ->where('checked_at', '>=', $start)
            ->groupBy('day')
            ->get()
            ->keyBy(fn ($row) => $row->day);

        return collect(range(6, 0))->map(function (int $i) use ($rows) {
            $day = Carbon::today()->subDays($i);
            $row = $rows->get($day->toDateString());
            $value = ! $row || (int) $row->total === 0 ? 100 : round($row->up_count / $row->total * 100);

            return ['label' => $day->translatedFormat('D'), 'value' => (int) $value];
        })->all();
    }

    public function recentIncidents()
    {
        return Incident::with('website')->latest('detected_at')->limit(6)->get();
    }

    public function websiteStatuses()
    {
        return Website::withCount(['checks' => fn ($q) => $q->where('checked_at', '>=', now()->subDay())])
            ->orderBy('name')
            ->get();
    }

    /**
     * Takes the site's uuid as a plain string: the picker's "clear" button sends
     * "" for "no site selected", which a typed ?string parameter can't accept
     * directly, so the empty case is normalized to null here.
     */
    public function selectWebsite(string $websiteUuid): void
    {
        $this->selectedWebsiteUuid = $websiteUuid !== '' ? $websiteUuid : null;
    }

    public function render()
    {
        $selectedWebsite = $this->selectedWebsiteUuid
            ? Website::where('uuid', $this->selectedWebsiteUuid)->first()
            : null;
        $websiteStatuses = $this->websiteStatuses();

        return view('livewire.dashboard', [
            'stats' => $this->stats($websiteStatuses),
            'weeklyUptime' => $this->weeklyUptime(),
            'recentIncidents' => $this->recentIncidents(),
            'websiteStatuses' => $websiteStatuses,
            // One query for the whole list instead of one per row - see Website::offlineIds().
            'offlineIds' => Website::offlineIds($websiteStatuses),
            'selectedWebsite' => $selectedWebsite,
            'trafficChecks' => $selectedWebsite
                ? $selectedWebsite->checks()->latest('checked_at')->limit(50)->get()->reverse()->values()
                : collect(),
        ]);
    }
}
