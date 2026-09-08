<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Pagination\AbstractPaginator;

class Website extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = [
        'name',
        'url',
        'is_active',
        'is_internal_only',
        'check_interval_minutes',
        'judol_scan_interval_minutes',
        'down_threshold_failures',
        'response_time_threshold_ms',
        'last_status',
        'consecutive_failures',
        'last_checked_at',
        'judol_scanned_at',
        'ssl_checked_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_internal_only' => 'boolean',
            'last_checked_at' => 'datetime',
            'judol_scanned_at' => 'datetime',
            'ssl_checked_at' => 'datetime',
        ];
    }

    public function checks(): HasMany
    {
        return $this->hasMany(WebsiteCheck::class);
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(Incident::class);
    }

    public function judolDetections(): HasMany
    {
        return $this->hasMany(JudolDetection::class);
    }

    /**
     * Injection sites - one per region of markup carrying spam, however many pages
     * render it. See JudolFinding for why this is separate from judolDetections().
     */
    public function judolFindings(): HasMany
    {
        return $this->hasMany(JudolFinding::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Whether this website's check interval has elapsed (or it has never been checked).
     *
     * A site that has been down for a full day straight is not probed automatically
     * at all: every check is a request that times out, and after a day of them the
     * next one is not news. It stays that way until somebody presses "Cek Sekarang",
     * which dispatches the job directly and bypasses this - see
     * App\Livewire\Websites\Show::checkNow(). One successful check clears
     * last_status back to 'up' and the normal cadence resumes on its own.
     *
     * Note the asymmetry with isOffline(): this must not cost a query per site, so
     * it reads consecutive_failures (already on the row) rather than the open
     * incident. The two agree in practice - a site accumulates a day of failures
     * precisely when its downtime incident is a day old.
     */
    public function isDueForCheck(): bool
    {
        if ($this->isPausedForOffline()) {
            return false;
        }

        return $this->last_checked_at === null
            || $this->last_checked_at->addMinutes($this->check_interval_minutes)->isPast();
    }

    /**
     * Whether automatic checking has been suspended because the site has been down
     * for at least a day - the same threshold isOffline() colours the dot gray at,
     * derived from the failure streak so a list of sites costs no extra queries.
     */
    public function isPausedForOffline(): bool
    {
        if ($this->last_status !== 'down') {
            return false;
        }

        // How many consecutive failures a full day of checking would produce. A site
        // checked every 5 minutes reaches a day at 288; one checked hourly at 24.
        $failuresPerDay = (int) ceil(1440 / max($this->check_interval_minutes, 1));

        return $this->consecutive_failures >= $failuresPerDay;
    }

    /**
     * Whether this website's judol-scan interval has elapsed (or it has never
     * been scanned) - a full-site crawl is much heavier than an uptime ping, so
     * it needs its own (longer) cadence rather than firing every schedule tick.
     */
    public function isDueForJudolScan(): bool
    {
        return $this->judol_scanned_at === null
            || $this->judol_scanned_at->addMinutes($this->judol_scan_interval_minutes)->isPast();
    }

    /**
     * Whether a judol crawl against this site is worth running at all.
     *
     * A crawl only makes sense on a site that actually responds: on a down site
     * every one of the up-to-max_pages fetches is guaranteed to fail the same way
     * the uptime probe just did, so the job burns its whole timeout budget (page
     * count x connect timeout x retries) to learn nothing, fails, and gets
     * redispatched on the next due tick. Uptime checking is what decides whether a
     * site is reachable - the scanner consumes that verdict instead of
     * rediscovering it one dead URL at a time.
     *
     * 'unknown' (never checked - typically a site added moments ago, or a typo'd
     * URL) is excluded too: websites:check runs every minute, so the first probe
     * lands well before the much longer judol_scan_interval_minutes elapses.
     *
     * Mirrors the existing "only when up" gate around SSL inspection in
     * WebsiteChecker::maybeCheckSsl().
     */
    public function isScannable(): bool
    {
        return $this->last_status === 'up';
    }

    /**
     * A site down for a full day straight stops being "urgent, just happened" (red)
     * and becomes "known, long-term offline" (gray) - the same underlying down state,
     * just a calmer color once it's no longer fresh news. Driven off the open
     * downtime incident's detected_at (when the current down streak began), not a
     * separate stored flag.
     */
    public function isOffline(): bool
    {
        if ($this->last_status !== 'down') {
            return false;
        }

        return $this->incidents()
            ->where('type', 'downtime')
            ->open()
            ->where('detected_at', '<=', now()->subDay())
            ->exists();
    }

    /**
     * Bulk version of isOffline() for list views - one query for the whole list
     * instead of one query per row (which isOffline() alone would mean in a loop).
     *
     * @param  iterable<Website>  $websites
     * @return array<int, true> website IDs that are offline, keyed by id for O(1) isset() lookups
     */
    public static function offlineIds(iterable $websites): array
    {
        // getCollection(), not collect($websites): wrapping a paginator in collect()
        // collects its own properties (items, perPage, currentPage, ...) rather than
        // the models inside it, so every lookup missed and no list view ever showed
        // a site as offline while the detail page - which calls isOffline() directly
        // - showed the same site gray.
        $rows = $websites instanceof AbstractPaginator ? $websites->getCollection() : collect($websites);

        $downIds = $rows->where('last_status', 'down')->pluck('id');

        if ($downIds->isEmpty()) {
            return [];
        }

        return Incident::where('type', 'downtime')
            ->open()
            ->whereIn('website_id', $downIds)
            ->where('detected_at', '<=', now()->subDay())
            ->pluck('website_id')
            ->flip()
            ->map(fn () => true)
            ->all();
    }

    /**
     * Whether this site's current outage was diagnosed as overload - the pattern
     * a DDoS produces, among other causes. See OutageCause for why this is only
     * ever a suspicion.
     */
    public function wasAttacked(): bool
    {
        if ($this->last_status !== 'down') {
            return false;
        }

        return $this->incidents()
            ->where('type', 'downtime')
            ->open()
            ->whereNotNull('meta->likely_cause')
            ->get(['meta'])
            ->contains(fn (Incident $incident) => self::isOverloadCause($incident));
    }

    /**
     * Bulk version of wasAttacked() for list views, matching offlineIds().
     *
     * @param  iterable<Website>  $websites
     * @return array<int, true> website IDs whose outage looks like overload
     */
    public static function attackedIds(iterable $websites): array
    {
        $rows = $websites instanceof AbstractPaginator ? $websites->getCollection() : collect($websites);
        $downIds = $rows->where('last_status', 'down')->pluck('id');

        if ($downIds->isEmpty()) {
            return [];
        }

        return Incident::where('type', 'downtime')
            ->open()
            ->whereIn('website_id', $downIds)
            ->whereNotNull('meta->likely_cause')
            ->get(['website_id', 'meta'])
            ->filter(fn (Incident $incident) => self::isOverloadCause($incident))
            ->pluck('website_id')
            ->flip()
            ->map(fn () => true)
            ->all();
    }

    /**
     * Whether a downtime incident's stored diagnosis is one of the overload
     * patterns. Matched on the marker OutageCause writes rather than on the
     * Indonesian prose, so rewording the label cannot silently change the colour
     * of every dot on the dashboard.
     */
    protected static function isOverloadCause(Incident $incident): bool
    {
        return (bool) ($incident->meta['cause_is_overload'] ?? false);
    }

    /**
     * Whether this site is currently carrying an unresolved response-time spike -
     * running slower than its own baseline while still serving.
     *
     * Read from the incident WebsiteChecker already opens rather than re-measured
     * here, so the dot and the incident list can never disagree about whether a
     * site is degraded.
     *
     * Deliberately one state, not a scale. How far over baseline a site is says
     * very little on its own - 3x a 50ms baseline and 3x a 2-second one are not
     * remotely the same situation - and splitting it invited a reading of severity
     * the measurement cannot support. Either traffic is elevated or it is not; how
     * bad it is belongs in the incident's own numbers, where the figures are.
     */
    public function hasTrafficSpike(): bool
    {
        return $this->incidents()
            ->where('type', 'attack')
            ->open()
            ->whereJsonContains('meta->reason', 'response_time_spike')
            ->exists();
    }

    /**
     * Bulk version of hasTrafficSpike() for list views - one query for the whole
     * list rather than one per row, matching offlineIds().
     *
     * @param  iterable<Website>  $websites
     * @return array<int, true> website IDs carrying an open spike
     */
    public static function spikingIds(iterable $websites): array
    {
        $rows = $websites instanceof AbstractPaginator ? $websites->getCollection() : collect($websites);
        $ids = $rows->pluck('id');

        if ($ids->isEmpty()) {
            return [];
        }

        return Incident::where('type', 'attack')
            ->open()
            ->whereJsonContains('meta->reason', 'response_time_spike')
            ->whereIn('website_id', $ids)
            ->pluck('website_id')
            ->flip()
            ->map(fn () => true)
            ->all();
    }

    /**
     * Every display status: its short name, the dot colour, the text colour a badge
     * uses, and the sentence explaining it.
     *
     * One table rather than a copy per view. The dot, the list badge and the detail
     * header all read this, so adding a status or renaming one cannot leave a view
     * behind showing a colour with no name (or a name in the wrong colour).
     *
     * @var array<string, array{label: string, dot: string, text: string, description: string}>
     */
    public const STATUSES = [
        'up' => [
            'label' => 'Online',
            'dot' => 'bg-emerald-500',
            'text' => 'text-emerald-700 dark:text-emerald-400',
            'badge' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-400 dark:ring-emerald-400/30',
            'description' => 'Situs merespons normal.',
        ],
        'spike' => [
            'label' => 'Trafik Tinggi',
            'dot' => 'bg-yellow-400',
            'text' => 'text-yellow-700 dark:text-yellow-500',
            'badge' => 'bg-yellow-50 text-yellow-800 ring-yellow-600/20 dark:bg-yellow-500/10 dark:text-yellow-500 dark:ring-yellow-400/30',
            'description' => 'Masih online, tapi merespons lebih lambat dari biasanya.',
        ],
        'attack' => [
            'label' => 'Down - Dugaan Serangan',
            'dot' => 'bg-red-500',
            'text' => 'text-red-700 dark:text-red-400',
            'badge' => 'bg-red-50 text-red-700 ring-red-600/20 dark:bg-red-500/10 dark:text-red-400 dark:ring-red-400/30',
            'description' => 'Melambat lalu berhenti merespons - dugaan kelebihan beban atau serangan DDoS. Perlu konfirmasi access log server.',
        ],
        'down' => [
            'label' => 'Down',
            'dot' => 'bg-red-500',
            'text' => 'text-red-700 dark:text-red-400',
            'badge' => 'bg-red-50 text-red-700 ring-red-600/20 dark:bg-red-500/10 dark:text-red-400 dark:ring-red-400/30',
            'description' => 'Berhenti merespons tanpa didahului lonjakan.',
        ],
        'offline' => [
            'label' => 'Offline',
            'dot' => 'bg-zinc-400 dark:bg-zinc-500',
            'text' => 'text-zinc-500 dark:text-zinc-400',
            'badge' => 'bg-zinc-100 text-zinc-600 ring-zinc-500/20 dark:bg-zinc-800 dark:text-zinc-400 dark:ring-zinc-400/20',
            'description' => 'Sudah down lebih dari 24 jam. Pengecekan otomatis dihentikan sampai ditekan "Cek Sekarang".',
        ],
        'internal' => [
            'label' => 'Jaringan Internal',
            'dot' => 'bg-amber-500',
            'text' => 'text-amber-700 dark:text-amber-400',
            'badge' => 'bg-amber-50 text-amber-700 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-400 dark:ring-amber-400/30',
            'description' => 'Kemungkinan hanya bisa diakses dari jaringan internal/instansi.',
        ],
        'unknown' => [
            'label' => 'Belum Diketahui',
            'dot' => 'bg-zinc-300 dark:bg-zinc-600',
            'text' => 'text-zinc-500 dark:text-zinc-400',
            'badge' => 'bg-zinc-100 text-zinc-500 ring-zinc-500/20 dark:bg-zinc-800 dark:text-zinc-400 dark:ring-zinc-400/20',
            'description' => 'Belum pernah dicek.',
        ],
    ];

    /**
     * The display config for a status key, falling back to 'unknown' so a status
     * this table has not caught up with renders as "belum diketahui" rather than
     * throwing on a live dashboard.
     *
     * @return array{label: string, dot: string, text: string, description: string}
     */
    public static function statusConfig(string $status): array
    {
        return self::STATUSES[$status] ?? self::STATUSES['unknown'];
    }

    /**
     * The status this site should be DISPLAYED as - distinct from last_status
     * (the raw monitoring state used for down_threshold_failures/incidents, which
     * never changes meaning). Consolidates the color/label logic that used to be
     * duplicated across every blade view that renders a status dot - see
     * <x-status-dot>.
     *
     * Ordering, most severe first:
     *
     * - 'internal' takes priority over 'offline': a site marked is_internal_only
     *   is expected to look "down" from here forever, so there's no "it's been a
     *   while, calm down" distinction to make for it the way there is for a
     *   normal outage.
     * - 'attack' outranks plain 'down'. Both are red, but a site that degraded
     *   into its outage is a different problem from one that simply stopped
     *   answering, and the dot is where that distinction is cheapest to make.
     * - spike states only apply while the site is still up: once it is down, the
     *   outage is the headline and the spike is part of its story, not a
     *   competing status.
     *
     * @param  bool  $isOffline  pass Website::offlineIds() membership for list views
     *                           (avoids an isOffline() query per row) or $this->isOffline() for a single record
     * @param  bool  $spike  pass Website::spikingIds() membership for list views
     *                       (or hasTrafficSpike() for a single record)
     * @param  bool  $wasAttacked  whether the open downtime incident was diagnosed as
     *                             overload - see App\Services\Monitoring\OutageCause
     */
    public function statusKey(bool $isOffline = false, bool $spike = false, bool $wasAttacked = false): string
    {
        return match (true) {
            $this->last_status === 'up' && $spike => 'spike',
            $this->last_status === 'up' => 'up',
            $this->last_status === 'down' && $this->is_internal_only => 'internal',
            $this->last_status === 'down' && $isOffline => 'offline',
            $this->last_status === 'down' && $wasAttacked => 'attack',
            $this->last_status === 'down' => 'down',
            default => 'unknown',
        };
    }

    /**
     * Uptime percentage over the last N days, based on recorded checks.
     */
    public function uptimePercentage(int $days = 7): float
    {
        $checks = $this->checks()->where('checked_at', '>=', now()->subDays($days))->get(['is_up']);

        if ($checks->isEmpty()) {
            return 100.0;
        }

        return round($checks->where('is_up', true)->count() / $checks->count() * 100, 1);
    }
}
