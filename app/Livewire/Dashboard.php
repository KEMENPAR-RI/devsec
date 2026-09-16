<?php

namespace App\Livewire;

use App\Models\Incident;
use App\Models\JudolDetection;
use App\Models\Website;
use App\Models\WebsiteCheck;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class Dashboard extends Component
{
    /** The picked site's uuid - no real primary key reaches the browser. */
    public ?string $selectedWebsiteUuid = null;

    /** Hours of history the all-sites chart averages over when no site is picked. */
    protected const OVERVIEW_HOURS = 24;

    /**
     * The KPI cards' colours, as whole literal class strings.
     *
     * Written out rather than assembled from a colour name at runtime: Tailwind
     * scans source text for complete class names, so a card whose class was built
     * as "bg-{$tone}-50" would render with no background at all in production.
     *
     * @var array<string, string>
     */
    protected const TONES = [
        'accent' => 'bg-accent-50 text-accent-600 dark:bg-accent-500/10 dark:text-accent-400',
        'up' => 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400',
        'down' => 'bg-red-50 text-red-600 dark:bg-red-500/10 dark:text-red-400',
        'warning' => 'bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-400',
        'caution' => 'bg-yellow-50 text-yellow-700 dark:bg-yellow-500/10 dark:text-yellow-500',
        'muted' => 'bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400',
    ];

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
    protected function statusCounts($websites, array $offline, array $attacked, array $maintenance): array
    {
        return $websites
            ->countBy(fn (Website $site) => $site->statusKey(
                isset($offline[$site->id]),
                isset($attacked[$site->id]),
                isset($maintenance[$site->id]),
            ))
            ->all();
    }

    /**
     * How many sites sit at each load level, from the same graded measurement the
     * list view's "Beban" column shows.
     *
     * @param  Collection<int, Website>  $websites
     * @return array<string, int>
     */
    protected function loadCounts($websites): array
    {
        return collect(Website::loadLevels($websites))
            ->countBy(fn (array $row) => $row['level'])
            ->all();
    }

    /**
     * The KPI row: every display status gets its own card, then the load levels,
     * then the two work queues.
     *
     * One card per status rather than a single "Down" total. The statuses are not
     * interchangeable - a suspected DDoS, a site that has been dark for a week and
     * one that is only reachable from the office network need three different
     * responses - and summing them hid exactly the distinction the dots are drawn
     * to make. The load cards count only sites that answered, so they partition the
     * online ones rather than adding to the status totals.
     *
     * @param  Collection<int, Website>  $websites
     * @param  array<string, int>  $counts  statusKey() tallies
     * @param  array<string, int>  $loads  loadLevel() tallies
     */
    public function stats($websites, array $counts, array $loads): array
    {
        $openIncidents = Incident::open()->count();
        $newJudol = JudolDetection::where('status', 'new')->count();

        $card = fn (string $label, int $value, string $icon, string $tone = 'accent') => [
            'label' => $label,
            'value' => (string) $value,
            'icon' => $icon,
            'accent' => self::TONES[$tone],
        ];

        return [
            $card('Total Situs', $websites->count(), 'globe'),
            $card('Online', $counts['up'] ?? 0, 'check-circle', 'up'),
            $card('Down - Dugaan DDoS', $counts['attack'] ?? 0, 'lightning', 'down'),
            $card('Down', $counts['down'] ?? 0, 'warning-circle', 'down'),
            $card('Offline', $counts['offline'] ?? 0, 'power', 'muted'),
            $card('Pemeliharaan', $counts['maintenance'] ?? 0, 'wrench', 'accent'),
            $card('Jaringan Internal', $counts['internal'] ?? 0, 'buildings', 'warning'),
            $card('Belum Diketahui', $counts['unknown'] ?? 0, 'question', 'muted'),
            $card('Beban Ringan', $loads['light'] ?? 0, 'gauge', 'up'),
            $card('Beban Sedang', $loads['moderate'] ?? 0, 'gauge', 'caution'),
            $card('Beban Berat', $loads['heavy'] ?? 0, 'gauge', 'down'),
            $card('Insiden Aktif', $openIncidents, 'bell-ringing', $openIncidents > 0 ? 'down' : 'accent'),
            $card('Deteksi Judol Baru', $newJudol, 'shield-warning', $newJudol > 0 ? 'down' : 'accent'),
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

    /**
     * The chart's series when no site is picked: the average response time across
     * every site, per hour, over the last day.
     *
     * The dashboard opens on this instead of an empty "pilih situs dulu" panel. The
     * per-site view is a drill-down; making it the only view left the largest panel
     * on the page carrying nothing until someone typed into a combobox.
     *
     * Averaged in the database rather than in PHP: a day of checks across every
     * monitored site runs to tens of thousands of rows, and this re-runs on every
     * wire:poll tick.
     *
     * @return Collection<int, array{label: string, ms: int, up: bool}>
     */
    protected function overviewSeries(): Collection
    {
        // The one piece of SQL here with no portable spelling. DATE() carried
        // weeklyUptime() across both drivers; hourly buckets have no such luck.
        $bucket = DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m-%d %H', checked_at)"
            : "DATE_FORMAT(checked_at, '%Y-%m-%d %H')";

        return WebsiteCheck::selectRaw(
            "{$bucket} as bucket, AVG(response_time_ms) as avg_ms, SUM(CASE WHEN is_up = 0 THEN 1 ELSE 0 END) as down_count"
        )
            ->where('checked_at', '>=', now()->subHours(self::OVERVIEW_HOURS))
            ->whereNotNull('response_time_ms')
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get()
            ->map(fn ($row) => [
                'label' => Carbon::createFromFormat('Y-m-d H', $row->bucket)->startOfHour()->translatedFormat('d M, H:i'),
                'ms' => (int) round((float) $row->avg_ms),
                // A single failed check anywhere in the hour colours the point red.
                // The average on its own would bury one site's outage under every
                // other site that answered fine.
                'up' => (int) $row->down_count === 0,
            ]);
    }

    /**
     * The chart's series for one site: its last 50 checks, oldest first.
     *
     * @return Collection<int, array{label: string, ms: int, up: bool}>
     */
    protected function websiteSeries(Website $website): Collection
    {
        return $website->checks()
            ->latest('checked_at')
            ->limit(50)
            ->get()
            ->reverse()
            ->values()
            ->map(fn (WebsiteCheck $check) => [
                'label' => $check->checked_at->translatedFormat('d M Y, H:i'),
                'ms' => (int) $check->response_time_ms,
                'up' => (bool) $check->is_up,
            ]);
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
        $maintenanceIds = Website::maintenanceIds($websiteStatuses);

        $responseSeries = $selectedWebsite
            ? $this->websiteSeries($selectedWebsite)
            : $this->overviewSeries();

        return view('livewire.dashboard', [
            'stats' => $this->stats(
                $websiteStatuses,
                $this->statusCounts($websiteStatuses, $offlineIds, $attackedIds, $maintenanceIds),
                $this->loadCounts($websiteStatuses),
            ),
            'weeklyUptime' => $this->weeklyUptime(),
            'recentIncidents' => $this->recentIncidents(),
            'websiteStatuses' => $websiteStatuses,
            'offlineIds' => $offlineIds,
            'attackedIds' => $attackedIds,
            'maintenanceIds' => $maintenanceIds,
            'selectedWebsite' => $selectedWebsite,
            'responseSeries' => $responseSeries,
            // Only a single site has one threshold to draw; the overview spans sites
            // that each set their own, so it gets no reference line at all.
            'responseThresholdMs' => $selectedWebsite?->response_time_threshold_ms,
            'responseCaption' => $selectedWebsite
                ? $selectedWebsite->url.' - '.$responseSeries->count().' pengecekan terakhir'
                : 'Semua situs - rata-rata per jam, '.self::OVERVIEW_HOURS.' jam terakhir',
        ]);
    }
}
