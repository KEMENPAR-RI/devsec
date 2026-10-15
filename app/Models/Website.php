<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use App\Services\Monitoring\JudolScanner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Pagination\AbstractPaginator;

class Website extends Model
{
    use Auditable, HasFactory, HasUuid;

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
        'dns_records',
        'dns_checked_at',
        'domain_expires_at',
        'domain_checked_at',
        'security_score',
        'security_grade',
        'security_findings',
        'security_checked_at',
        'reputation_status',
        'reputation_threats',
        'reputation_checked_at',
    ];

    public function auditEventPrefix(): string
    {
        return 'situs';
    }

    /** The monitor rewrites these on every probe; only operator edits are news. */
    public function auditIgnored(): array
    {
        return [
            'id', 'uuid', 'created_at', 'updated_at',
            'last_status', 'consecutive_failures', 'last_checked_at',
            'judol_scanned_at', 'ssl_checked_at',
            // DNS drift is reported as its own incident, with a readable diff -
            // dumping the raw record set into the audit log too would bury the
            // operator edits this log exists for.
            'dns_records', 'dns_checked_at', 'domain_checked_at',
            // The grade is measured, not decided by anyone - it belongs on the
            // site page, not in the who-changed-what trail.
            'security_score', 'security_grade', 'security_findings', 'security_checked_at',
            'reputation_status', 'reputation_threats', 'reputation_checked_at',
        ];
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_internal_only' => 'boolean',
            'last_checked_at' => 'datetime',
            'judol_scanned_at' => 'datetime',
            'ssl_checked_at' => 'datetime',
            'dns_records' => 'array',
            'dns_checked_at' => 'datetime',
            'domain_expires_at' => 'date',
            'domain_checked_at' => 'datetime',
            'security_score' => 'integer',
            'security_findings' => 'array',
            'security_checked_at' => 'datetime',
            'reputation_threats' => 'array',
            'reputation_checked_at' => 'datetime',
        ];
    }

    public function checks(): HasMany
    {
        return $this->hasMany(WebsiteCheck::class);
    }

    public function latestCheck(): HasOne
    {
        return $this->hasOne(WebsiteCheck::class)->latestOfMany('checked_at');
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
     * A site that has been down for a full day straight drops to one automatic probe
     * every 24 hours: every check is a request that times out, and after a day of
     * them the next one is not news. "Cek Sekarang" still dispatches the job directly
     * and bypasses this - see App\Livewire\Websites\Show::checkNow(). One successful
     * check clears last_status back to 'up' and the normal cadence resumes on its own.
     *
     * Note the asymmetry with isOffline(): this must not cost a query per site, so
     * it reads consecutive_failures (already on the row) rather than the open
     * incident. The two agree in practice - a site accumulates a day of failures
     * precisely when its downtime incident is a day old.
     */
    public function isDueForCheck(): bool
    {
        if ($this->isPausedForOffline()) {
            return $this->last_checked_at === null || $this->last_checked_at->addDay()->isPast();
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
     *
     * 'down' is not automatically excluded, because the uptime probe calls a site
     * down for two different reasons. A host that never answers has nothing to
     * crawl, as above. But a host answering 403 - a WAF refusing the probe while
     * the site itself serves normally - is up in every sense that matters here,
     * and skipping it is how a compromised site ends up with judol incidents but
     * an empty findings list: manual URL checks kept flagging pages, while the
     * crawl that builds findings was never allowed to run. See
     * JudolScanner::crawl(), which stops on its own if nothing is reachable.
     */
    public function isScannable(): bool
    {
        if ($this->last_status === 'up') {
            return true;
        }

        if ($this->last_status !== 'down') {
            return false;
        }

        // Only an access refusal, not any answer at all: a server erroring 5xx on
        // its own homepage is failing, and its other pages will fail the same way.
        // The absence of a status means the request never got that far (timeout,
        // DNS, refused connection) - genuinely nothing to crawl.
        return in_array(
            (int) $this->checks()->latest('checked_at')->value('status_code'),
            JudolScanner::ACCESS_REFUSED_STATUSES,
            true
        );
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

    public function maintenanceWindows(): HasMany
    {
        return $this->hasMany(MaintenanceWindow::class);
    }

    /**
     * Whether a scheduled maintenance window covers this site right now.
     *
     * Includes the all-sites windows (website_id null), because that is what a
     * server migration looks like and the point of the feature is that nobody has
     * to remember to list every site by hand at 2am.
     */
    public function isUnderMaintenance(): bool
    {
        return MaintenanceWindow::query()->forWebsite($this->id)->covering()->exists();
    }

    public function activeMaintenance(): ?MaintenanceWindow
    {
        return MaintenanceWindow::query()->forWebsite($this->id)->covering()->latest('starts_at')->first();
    }

    /**
     * Bulk version of isUnderMaintenance() for list views, matching offlineIds().
     *
     * @param  iterable<Website>  $websites
     * @return array<int, true> website IDs currently under maintenance, keyed by id
     */
    public static function maintenanceIds(iterable $websites): array
    {
        $rows = $websites instanceof AbstractPaginator ? $websites->getCollection() : collect($websites);
        $ids = $rows->pluck('id');

        if ($ids->isEmpty()) {
            return [];
        }

        $windows = MaintenanceWindow::query()->covering()->get(['website_id']);

        // One all-sites window covers the whole page, so it short-circuits the
        // per-id matching entirely.
        if ($windows->contains(fn (MaintenanceWindow $window) => $window->website_id === null)) {
            return $ids->flip()->map(fn () => true)->all();
        }

        return $windows->pluck('website_id')
            ->intersect($ids)
            ->flip()
            ->map(fn () => true)
            ->all();
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
     * Every display status: its short name, the dot colour, the text colour a badge
     * uses, and the sentence explaining it.
     *
     * One table rather than a copy per view. The dot, the list badge and the detail
     * header all read this, so adding a status or renaming one cannot leave a view
     * behind showing a colour with no name (or a name in the wrong colour).
     *
     * @var array<string, array{label: string, dot: string, text: string, card: string, description: string}>
     */
    public const STATUSES = [
        'up' => [
            'label' => 'Online',
            'dot' => 'bg-emerald-500',
            'text' => 'text-emerald-700 dark:text-emerald-400',
            'badge' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-400 dark:ring-emerald-400/30',
            'card' => 'bg-accent-600 bg-linear-to-r from-emerald-500/15 to-transparent to-60% border-emerald-400/50 text-emerald-300',
            'description' => 'Situs merespons normal.',
        ],
        'attack' => [
            'label' => 'Down - Dugaan DDoS',
            'dot' => 'bg-red-500',
            'text' => 'text-red-700 dark:text-red-400',
            'badge' => 'bg-red-50 text-red-700 ring-red-600/20 dark:bg-red-500/10 dark:text-red-400 dark:ring-red-400/30',
            'card' => 'bg-accent-600 bg-linear-to-r from-red-500/15 to-transparent to-60% border-red-400/50 text-red-300',
            'description' => 'Melambat lalu berhenti merespons - dugaan kelebihan beban atau serangan DDoS. Perlu konfirmasi access log server.',
        ],
        'down' => [
            'label' => 'Down',
            'dot' => 'bg-red-500',
            'text' => 'text-red-700 dark:text-red-400',
            'badge' => 'bg-red-50 text-red-700 ring-red-600/20 dark:bg-red-500/10 dark:text-red-400 dark:ring-red-400/30',
            'card' => 'bg-accent-600 bg-linear-to-r from-red-500/15 to-transparent to-60% border-red-400/50 text-red-300',
            'description' => 'Berhenti merespons tanpa didahului lonjakan.',
        ],
        'offline' => [
            'label' => 'Offline',
            'dot' => 'bg-zinc-400 dark:bg-zinc-500',
            'text' => 'text-zinc-500 dark:text-zinc-400',
            'badge' => 'bg-zinc-100 text-zinc-600 ring-zinc-500/20 dark:bg-zinc-800 dark:text-zinc-400 dark:ring-zinc-400/20',
            'card' => 'bg-accent-600 bg-linear-to-r from-zinc-400/15 to-transparent to-60% border-zinc-400/40 text-zinc-300',
            'description' => 'Sudah down lebih dari 24 jam. Pengecekan otomatis dicoba ulang setiap 24 jam, atau tekan "Cek Sekarang".',
        ],
        'maintenance' => [
            'label' => 'Pemeliharaan',
            'dot' => 'bg-sky-500',
            'text' => 'text-sky-700 dark:text-sky-400',
            'badge' => 'bg-sky-50 text-sky-700 ring-sky-600/20 dark:bg-sky-500/10 dark:text-sky-400 dark:ring-sky-400/30',
            'card' => 'bg-accent-600 bg-linear-to-r from-sky-500/15 to-transparent to-60% border-sky-400/50 text-sky-300',
            'description' => 'Sedang dalam jendela pemeliharaan terjadwal - insiden dan notifikasi dihentikan sementara.',
        ],
        'internal' => [
            'label' => 'Jaringan Internal',
            'dot' => 'bg-amber-500',
            'text' => 'text-amber-700 dark:text-amber-400',
            'badge' => 'bg-amber-50 text-amber-700 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-400 dark:ring-amber-400/30',
            'card' => 'bg-accent-600 bg-linear-to-r from-amber-500/15 to-transparent to-60% border-amber-400/50 text-amber-300',
            'description' => 'Kemungkinan hanya bisa diakses dari jaringan internal/instansi.',
        ],
        'unknown' => [
            'label' => 'Belum Diketahui',
            'dot' => 'bg-zinc-300 dark:bg-zinc-600',
            'text' => 'text-zinc-500 dark:text-zinc-400',
            'badge' => 'bg-zinc-100 text-zinc-500 ring-zinc-500/20 dark:bg-zinc-800 dark:text-zinc-400 dark:ring-zinc-400/20',
            'card' => 'bg-accent-600 bg-linear-to-r from-zinc-400/15 to-transparent to-60% border-zinc-400/40 text-zinc-300',
            'description' => 'Belum pernah dicek.',
        ],
    ];

    /**
     * How hard the server appeared to be working on the last check, as three levels.
     *
     * "Beban", not "traffic": this app probes from outside and never sees a request
     * count. What it measures is how long one response took, and a site can be slow
     * because it is popular, because a query went bad, or because the disk is full.
     * Naming the column after load keeps it to what the measurement supports - an
     * operator reading "Traffic: Tinggi" would reasonably conclude visitors are up,
     * which this app has no way of knowing.
     *
     * @var array<string, array{label: string, badge: string, dot: string}>
     */
    public const LOAD_LEVELS = [
        'light' => [
            'label' => 'Ringan',
            'dot' => 'bg-emerald-500',
            'badge' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-400 dark:ring-emerald-400/30',
        ],
        'moderate' => [
            'label' => 'Sedang',
            'dot' => 'bg-yellow-400',
            'badge' => 'bg-yellow-50 text-yellow-800 ring-yellow-600/20 dark:bg-yellow-500/10 dark:text-yellow-500 dark:ring-yellow-400/30',
        ],
        'heavy' => [
            'label' => 'Berat',
            'dot' => 'bg-red-500',
            'badge' => 'bg-red-50 text-red-700 ring-red-600/20 dark:bg-red-500/10 dark:text-red-400 dark:ring-red-400/30',
        ],
        'unknown' => [
            'label' => '-',
            'dot' => 'bg-zinc-300 dark:bg-zinc-600',
            'badge' => 'bg-zinc-100 text-zinc-500 ring-zinc-500/20 dark:bg-zinc-800 dark:text-zinc-400 dark:ring-zinc-400/20',
        ],
    ];

    /** Times over baseline where load stops reading as light. */
    protected const LOAD_MODERATE_FACTOR = 2.0;

    /** Times over baseline where load reads as heavy - the same 3x that opens a spike incident. */
    protected const LOAD_HEAVY_FACTOR = 3.0;

    /**
     * Baseline checks sampled. Matches the window WebsiteChecker's own spike
     * detection averages over, so the column and the incident agree.
     */
    protected const LOAD_BASELINE_CHECKS = 10;

    /**
     * A baseline below this is too small for a ratio to mean anything - doubling
     * 12ms is noise, not load - so such sites are reported light rather than
     * flickering between levels on jitter alone.
     */
    protected const LOAD_MIN_BASELINE_MS = 50.0;

    /**
     * The load level from the last check, measured against this site's own recent
     * average rather than a fixed number of milliseconds.
     *
     * Relative because the sites monitored here differ enormously in how fast they
     * are when perfectly healthy: an institutional site on shared hosting answering
     * in 2 seconds is normal for it, and a fixed threshold would paint it amber
     * forever while saying nothing about whether anything changed.
     *
     * @param  float|null  $baselineMs  pass Website::loadBaselines() for list views
     *                                  (avoids a query per row); computed here when omitted
     */
    public function loadLevel(?float $baselineMs = null): string
    {
        $last = $this->checks()->latest('checked_at')->first();

        // A failed check has no meaningful latency to grade: it either timed out or
        // never connected, and the status column is already saying so.
        if (! $last || ! $last->is_up || ! $last->response_time_ms) {
            return 'unknown';
        }

        $baseline = $baselineMs ?? $this->loadBaseline($last->id);

        if (! $baseline || $baseline < self::LOAD_MIN_BASELINE_MS) {
            return 'light';
        }

        $ratio = $last->response_time_ms / $baseline;

        return match (true) {
            $ratio >= self::LOAD_HEAVY_FACTOR => 'heavy',
            $ratio >= self::LOAD_MODERATE_FACTOR => 'moderate',
            default => 'light',
        };
    }

    /**
     * This site's recent average response time over its successful checks.
     *
     * The check being graded is excluded, exactly as
     * WebsiteChecker::detectResponseTimeAnomaly() excludes it: leaving it in lets a
     * spike raise the very average it is measured against, which flattens the ratio
     * and hides the load it was supposed to reveal. With ten samples a 4x spike
     * reads as 2.7x - enough to drop it a whole level.
     *
     * @param  int|null  $exceptCheckId  the check being graded
     */
    public function loadBaseline(?int $exceptCheckId = null): ?float
    {
        $average = $this->checks()
            ->where('is_up', true)
            ->whereNotNull('response_time_ms')
            ->when($exceptCheckId, fn ($q) => $q->where('id', '!=', $exceptCheckId))
            ->latest('checked_at')
            ->limit(self::LOAD_BASELINE_CHECKS)
            ->avg('response_time_ms');

        return $average ? (float) $average : null;
    }

    /**
     * Load levels for a whole list, in two queries rather than two per row.
     *
     * Both the last check and the baseline average are fetched for every site at
     * once and graded in PHP - the same arithmetic loadLevel() does, just not once
     * per website.
     *
     * The measurement each level was graded from travels with it, so the view can
     * put the actual milliseconds in a tooltip without going back to the database.
     *
     * @param  iterable<Website>  $websites
     * @return array<int, array{level: string, response_time_ms: int|null}>
     */
    public static function loadLevels(iterable $websites): array
    {
        $rows = $websites instanceof AbstractPaginator ? $websites->getCollection() : collect($websites);
        $ids = $rows->pluck('id');

        if ($ids->isEmpty()) {
            return [];
        }

        // The most recent check per site, whether it succeeded or not: a site whose
        // latest check failed has no latency to grade, same as loadLevel() decides.
        // Ordered oldest-first so keyBy() leaves the newest row per site standing -
        // later keys overwrite earlier ones.
        $latest = WebsiteCheck::whereIn('website_id', $ids)
            ->oldest('checked_at')
            ->get(['id', 'website_id', 'is_up', 'response_time_ms'])
            ->keyBy('website_id');

        // Baselines from the successful checks inside the same window loadBaseline()
        // uses, averaged per site - and, like it, excluding the check being graded so
        // a spike cannot inflate the average it is measured against.
        $baselines = WebsiteCheck::whereIn('website_id', $ids)
            ->where('is_up', true)
            ->whereNotNull('response_time_ms')
            ->orderByDesc('checked_at')
            ->get(['id', 'website_id', 'response_time_ms'])
            ->groupBy('website_id')
            ->map(fn ($checks, $websiteId) => $checks
                ->reject(fn ($check) => $check->id === $latest->get($websiteId)?->id)
                ->take(self::LOAD_BASELINE_CHECKS)
                ->avg('response_time_ms'));

        return $rows->mapWithKeys(function (Website $website) use ($latest, $baselines) {
            $check = $latest->get($website->id);

            if (! $check || ! $check->is_up || ! $check->response_time_ms) {
                return [$website->id => ['level' => 'unknown', 'response_time_ms' => null]];
            }

            $baseline = (float) ($baselines[$website->id] ?? 0);
            $ratio = $baseline >= self::LOAD_MIN_BASELINE_MS ? $check->response_time_ms / $baseline : 0.0;

            return [$website->id => [
                'level' => match (true) {
                    $ratio >= self::LOAD_HEAVY_FACTOR => 'heavy',
                    $ratio >= self::LOAD_MODERATE_FACTOR => 'moderate',
                    default => 'light',
                },
                'response_time_ms' => $check->response_time_ms,
            ]];
        })->all();
    }

    /**
     * The display config for a load level, falling back to 'unknown'.
     *
     * @return array{label: string, badge: string, dot: string}
     */
    public static function loadConfig(string $level): array
    {
        return self::LOAD_LEVELS[$level] ?? self::LOAD_LEVELS['unknown'];
    }

    /**
     * The display config for a status key, falling back to 'unknown' so a status
     * this table has not caught up with renders as "belum diketahui" rather than
     * throwing on a live dashboard.
     *
     * @return array{label: string, dot: string, card: string, text: string, badge: string, description: string}
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
     *
     * A site that is still serving has exactly one status here: 'up'. Response-time
     * degradation is deliberately NOT a status - it is still online, and a separate
     * colour for it drowned the two states an operator can actually act on. How
     * hard the server is working belongs in the load column (see LOAD_LEVELS), and
     * the slow-down itself is still recorded as an incident, where it goes on to
     * corroborate the DDoS diagnosis in OutageCause.
     *
     * @param  bool  $isOffline  pass Website::offlineIds() membership for list views
     *                           (avoids an isOffline() query per row) or $this->isOffline() for a single record
     * @param  bool  $wasAttacked  whether the open downtime incident was diagnosed as
     *                             overload - see App\Services\Monitoring\OutageCause
     * @param  bool  $underMaintenance  pass Website::maintenanceIds() membership for list
     *                                  views, or $this->isUnderMaintenance() for one record
     */
    public function statusKey(bool $isOffline = false, bool $wasAttacked = false, bool $underMaintenance = false): string
    {
        return match (true) {
            $underMaintenance => 'maintenance',
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
