<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Pagination\AbstractPaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class Website extends Model
{
    use Auditable, HasFactory, HasUuid;

    protected $fillable = [
        'name',
        'url',
        'is_active',
        'is_internal_only',
        'is_priority',
        'check_interval_minutes',
        'down_threshold_failures',
        'response_time_threshold_ms',
        'last_status',
        'consecutive_failures',
        'last_checked_at',
        'ssl_checked_at',
        'ssl_expires_at',
        'ssl_issuer',
        'ssl_names',
        'ssl_chain_valid',
        'ssl_hostname_match',
        'ssl_error',
        'dns_records',
        'dns_checked_at',
        'dns_ip_rotates',
        'dns_ip_drift_at',
        'domain_expires_at',
        'domain_expiry_source',
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
            'ssl_checked_at',
            'ssl_expires_at', 'ssl_issuer', 'ssl_names', 'ssl_chain_valid', 'ssl_hostname_match', 'ssl_error',
            // DNS drift is reported as its own incident, with a readable diff -
            // dumping the raw record set into the audit log too would bury the
            // operator edits this log exists for.
            'dns_records', 'dns_checked_at', 'domain_checked_at', 'dns_ip_drift_at', 'domain_expiry_source',
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
            'is_priority' => 'boolean',
            'last_checked_at' => 'datetime',
            'ssl_checked_at' => 'datetime',
            'ssl_expires_at' => 'datetime',
            'ssl_names' => 'array',
            'ssl_chain_valid' => 'boolean',
            'ssl_hostname_match' => 'boolean',
            'dns_records' => 'array',
            'dns_checked_at' => 'datetime',
            'dns_ip_rotates' => 'boolean',
            'dns_ip_drift_at' => 'datetime',
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

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Shared list order: down (not under maintenance), then warning (Gangguan, not under
     * maintenance), then priority, then the rest.
     * Down group: most recently down first (open downtime incident, else last check, like TvBoard);
     * other groups newest-added first; id as the pagination tie-breaker.
     */
    public function scopeOrderForList(Builder $query): Builder
    {
        $maintenance = MaintenanceWindow::query()->covering()
            ->where(fn (Builder $q) => $q
                ->whereColumn('maintenance_windows.website_id', $this->qualifyColumn('id'))
                ->orWhereNull('maintenance_windows.website_id'))
            ->toBase();
        $downSince = Incident::query()
            ->selectRaw('MAX(incidents.detected_at)')
            ->whereColumn('incidents.website_id', $this->qualifyColumn('id'))
            ->where('incidents.type', 'downtime')
            ->where('incidents.status', 'open')
            ->toBase();
        $isDown = "{$this->qualifyColumn('last_status')} = 'down' AND NOT EXISTS ({$maintenance->toSql()})";
        $isWarning = "{$this->qualifyColumn('last_status')} = 'warning' AND NOT EXISTS ({$maintenance->toSql()})";

        return $query
            ->orderByRaw(
                "CASE WHEN {$isDown} THEN 0 WHEN {$isWarning} THEN 1 WHEN {$this->qualifyColumn('is_priority')} = 1 THEN 2 ELSE 3 END",
                [...$maintenance->getBindings(), ...$maintenance->getBindings()],
            )
            ->orderByRaw(
                "CASE WHEN {$isDown} THEN COALESCE(({$downSince->toSql()}), {$this->qualifyColumn('last_checked_at')}) END DESC",
                [...$maintenance->getBindings(), ...$downSince->getBindings()],
            )
            ->orderByDesc($this->qualifyColumn('created_at'))
            ->orderByDesc($this->qualifyColumn('id'));
    }

    /**
     * Whether this website's check interval has elapsed (or it has never been checked).
     *
     * A site down for a full day (isPausedForOffline()) is re-checked every
     * monitoring.offline_recheck_minutes (see effectiveCheckIntervalMinutes()) until
     * it recovers. One successful check clears last_status back to 'up' and the
     * normal interval resumes on its own.
     */
    public function isDueForCheck(): bool
    {
        if ($this->last_checked_at === null) {
            return true;
        }

        // Slow site: re-checked within seconds so it reaches Down (or recovers) quickly.
        if ($this->last_status === 'warning') {
            $seconds = filter_var(config('monitoring.warning_recheck_seconds'), FILTER_VALIDATE_INT, [
                'options' => ['default' => 20, 'min_range' => 15, 'max_range' => 30],
            ]);

            // 5s early so the 15s scheduler tick does not stretch it by a tick.
            return $this->last_checked_at->addSeconds($seconds - 5)->isPast();
        }

        // An unconfirmed failure is re-checked every minute instead of waiting a full interval.
        $verifying = $this->last_status !== 'down'
            && $this->consecutive_failures > 0
            && $this->consecutive_failures < $this->down_threshold_failures;

        // 30s early so the every-minute scheduler does not stretch the interval by a tick.
        return $this->last_checked_at
            ->addMinutes($verifying ? 1 : $this->effectiveCheckIntervalMinutes())
            ->subSeconds(30)
            ->isPast();
    }

    /**
     * Minutes between automatic checks: the site's own interval (also during the
     * first day of an outage), or monitoring.offline_recheck_minutes once it has been
     * down a full day. Reads only the row and config, so it is safe to call per site
     * in a loop.
     */
    public function effectiveCheckIntervalMinutes(): int
    {
        if (! $this->isPausedForOffline()) {
            return max((int) $this->check_interval_minutes, 1);
        }

        // Out-of-range or non-numeric values fall back to 10, never to 1.
        return filter_var(config('monitoring.offline_recheck_minutes'), FILTER_VALIDATE_INT, [
            'options' => ['default' => 10, 'min_range' => 5, 'max_range' => 60],
        ]);
    }

    /**
     * Whether the site has been down for at least a day - the same threshold
     * isOffline() colours the dot gray at, derived from the failure streak so a
     * list of sites costs no extra queries. Switches checking to the offline
     * re-check interval (effectiveCheckIntervalMinutes()); WebsiteChecker uses it to
     * stay quiet about a known long outage.
     */
    public function isPausedForOffline(): bool
    {
        if ($this->last_status !== 'down') {
            return false;
        }

        // How many consecutive failures a full day at the site's own interval produces:
        // 288 at 5 minutes, 24 hourly. Must not use effectiveCheckIntervalMinutes()
        // (it depends on this); failures only grow while down, so once reached it holds.
        $failuresPerDay = (int) ceil(1440 / max((int) $this->check_interval_minutes, 1));

        return $this->consecutive_failures >= $failuresPerDay;
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
        'warning' => [
            'label' => 'Gangguan',
            'dot' => 'bg-yellow-500',
            'text' => 'text-yellow-700 dark:text-yellow-400',
            'badge' => 'bg-yellow-50 text-yellow-800 ring-yellow-600/20 dark:bg-yellow-400/10 dark:text-yellow-400 dark:ring-yellow-400/30',
            'card' => 'bg-accent-600 bg-linear-to-r from-yellow-400/15 to-transparent to-60% border-yellow-400/50 text-yellow-300',
            'description' => 'Situs masih merespons tetapi lambat, melebihi ambang waktu respons. Dicek ulang setiap beberapa detik dan menjadi Down bila tetap lambat.',
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
        // Same window + tie-break as loadLevels(), so the detail page matches the dashboard.
        $last = $this->checks()
            ->where('checked_at', '>=', now()->subDays(WebsiteCheck::RANKED_WINDOW_DAYS))
            ->latest('checked_at')
            ->latest('id')
            ->first();

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
        // Averaged in PHP: avg() on the builder would drop the LIMIT and average all history.
        $average = $this->checks()
            ->where('is_up', true)
            ->whereNotNull('response_time_ms')
            ->where('checked_at', '>=', now()->subDays(WebsiteCheck::RANKED_WINDOW_DAYS))
            ->when($exceptCheckId, fn ($q) => $q->where('id', '!=', $exceptCheckId))
            ->latest('checked_at')
            ->latest('id')
            ->limit(self::LOAD_BASELINE_CHECKS)
            ->pluck('response_time_ms')
            ->avg();

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
        $latest = self::rankedChecks($ids, 1)
            ->keyBy('website_id');

        // Baselines: the last LOAD_BASELINE_CHECKS successful checks in the ranked window,
        // excluding the check being graded - the same rows loadBaseline() averages.
        // One extra row per site covers the excluded latest check.
        $baselines = self::rankedChecks($ids, self::LOAD_BASELINE_CHECKS + 1, true)
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
     * The newest $limit checks per site (newest first), bounded in SQL via ROW_NUMBER().
     *
     * @param  Collection<int, int>  $ids
     * @return Collection<int, object{id: int, website_id: int, is_up: bool, response_time_ms: ?int}>
     */
    protected static function rankedChecks($ids, int $limit, bool $successfulOnly = false): Collection
    {
        $ranked = WebsiteCheck::query()
            ->select(['id', 'website_id', 'is_up', 'response_time_ms', 'checked_at'])
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY website_id ORDER BY checked_at DESC, id DESC) AS rn')
            ->whereIn('website_id', $ids)
            ->where('checked_at', '>=', now()->subDays(WebsiteCheck::RANKED_WINDOW_DAYS))
            ->when($successfulOnly, fn ($q) => $q->where('is_up', true)->whereNotNull('response_time_ms'));

        return DB::query()
            ->fromSub($ranked, 'ranked')
            ->where('rn', '<=', $limit)
            ->orderBy('website_id')
            ->orderBy('rn')
            ->get(['id', 'website_id', 'is_up', 'response_time_ms'])
            ->map(fn ($row) => (object) [
                'id' => (int) $row->id,
                'website_id' => (int) $row->website_id,
                'is_up' => (bool) $row->is_up,
                'response_time_ms' => $row->response_time_ms === null ? null : (int) $row->response_time_ms,
            ]);
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
     * - 'warning' (reverses an earlier decision, see ADR 0001): the site still
     *   answers, but slower than response_time_threshold_ms, and becomes 'down'
     *   once the slow streak reaches down_threshold_failures. It is the only
     *   degradation shown as a status; how hard the server is working otherwise
     *   still belongs in the load column (see LOAD_LEVELS). Maintenance outranks it.
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
            $this->last_status === 'warning' => 'warning',
            $this->last_status === 'up' => 'up',
            $this->last_status === 'down' && $this->is_internal_only => 'internal',
            $this->last_status === 'down' && $isOffline => 'offline',
            $this->last_status === 'down' && $wasAttacked => 'attack',
            $this->last_status === 'down' => 'down',
            default => 'unknown',
        };
    }

    /**
     * Uptime percentage over the last N days, based on recorded checks; null when there are none.
     */
    public function uptimePercentage(int $days = 7): ?float
    {
        $row = $this->checks()
            ->where('checked_at', '>=', now()->subDays($days))
            ->toBase()
            ->selectRaw('COUNT(*) AS total, SUM(CASE WHEN '.WebsiteCheck::UP_SQL.' THEN 1 ELSE 0 END) AS up_count')
            ->first();

        if (! $row || (int) $row->total === 0) {
            return null;
        }

        return round((int) $row->up_count / (int) $row->total * 100, 1);
    }
}
