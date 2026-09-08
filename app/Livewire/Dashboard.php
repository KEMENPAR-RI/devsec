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
     * Counted from the same statusKey() the dots and badges use, so a card total
     * and the list underneath it can never disagree.
     *
     * @param  Collection<int, Website>  $websites
     * @return array<string, int>
     */
    protected function statusCounts($websites, array $offline, array $spiking, array $attacked): array
    {
        return $websites
            ->countBy(fn (Website $site) => $site->statusKey(
                isset($offline[$site->id]),
                isset($spiking[$site->id]),
                isset($attacked[$site->id]),
            ))
            ->all();
    }

    public function stats($websites, array $counts): array
    {
        $openIncidents = Incident::open()->count();
        $newJudol = JudolDetection::where('status', 'new')->count();

        $down = ($counts['down'] ?? 0) + ($counts['attack'] ?? 0) + ($counts['offline'] ?? 0) + ($counts['internal'] ?? 0);
        $slow = $counts['spike'] ?? 0;

        return [
            [
                'label' => 'Total Situs',
                'value' => (string) $websites->count(),
                'icon' => 'globe',
            ],
            [
                'label' => 'Online',
                'value' => (string) ($counts['up'] ?? 0),
                'icon' => 'check-circle',
                'tone' => 'up',
            ],
            [
                'label' => 'Slow',
                'value' => (string) $slow,
                'icon' => 'gauge',
                'tone' => $slow > 0 ? 'slow' : null,
            ],
            [
                'label' => 'Down',
                'value' => (string) $down,
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

        // One query each for the whole list, shared between the cards and the rows.
        $offlineIds = Website::offlineIds($websiteStatuses);
        $attackedIds = Website::attackedIds($websiteStatuses);
        $spikingIds = Website::spikingIds($websiteStatuses);

        return view('livewire.dashboard', [
            'stats' => $this->stats(
                $websiteStatuses,
                $this->statusCounts($websiteStatuses, $offlineIds, $spikingIds, $attackedIds),
            ),
            'weeklyUptime' => $this->weeklyUptime(),
            'recentIncidents' => $this->recentIncidents(),
            'websiteStatuses' => $websiteStatuses,
            'offlineIds' => $offlineIds,
            'attackedIds' => $attackedIds,
            'spikingIds' => $spikingIds,
            'selectedWebsite' => $selectedWebsite,
            'responseChecks' => $selectedWebsite
                ? $selectedWebsite->checks()->latest('checked_at')->limit(50)->get()->reverse()->values()
                : collect(),
        ]);
    }
}
