<?php

namespace App\Livewire\Websites;

use App\Jobs\CheckWebsiteJob;
use App\Livewire\Concerns\InteractsWithToasts;
use App\Models\AuditLog;
use App\Models\Website;
use App\Models\WebsiteCheck;
use App\Rules\PublicUrl;
use App\Services\AppSettings;
use App\Services\Security\OutboundUrlGuard;
use App\Support\Url as SiteUrl;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

#[Layout('components.layouts.app')]
class Index extends Component
{
    use InteractsWithToasts;
    use WithPagination;

    #[Url]
    public string $search = '';

    /** '' for all, otherwise a Website::STATUSES key. */
    #[Url]
    public string $status = '';

    /** '' for all, otherwise 'active' or 'inactive'. */
    #[Url]
    public string $activity = '';

    /** '' for all, otherwise 'priority' or 'secondary'. */
    #[Url]
    public string $category = '';

    public bool $showModal = false;

    public ?Website $editing = null;

    public string $name = '';

    public string $url = '';

    public int $checkIntervalMinutes = 5;

    public int $downThresholdFailures = 3;

    public int $responseTimeThresholdMs = 15000;

    public bool $isActive = true;

    public bool $isInternalOnly = false;

    public bool $dnsIpRotates = false;

    public bool $isPriority = false;

    /**
     * Typed by hand for the registries that publish no RDAP - .id among them.
     * A lookup that comes back empty never clears it.
     */
    public string $domainExpiresAt = '';

    public bool $showBulkModal = false;

    public string $bulkInput = '';

    /** @var array{created: int, skipped: array<int, string>, invalid: array<int, string>}|null */
    public ?array $bulkResult = null;

    public function mount(): void
    {
        $this->authorize('situs.view');
    }

    public function updatingSearch(): void
    {
        $this->resetPage();

        // Otherwise a site ticked under an earlier search stays selected while
        // invisible, and a later mass edit silently changes it too.
        $this->selected = [];
    }

    public function openCreate(): void
    {
        $this->authorize('situs.create');

        $this->reset(['editing', 'name', 'url', 'checkIntervalMinutes', 'downThresholdFailures', 'responseTimeThresholdMs', 'isActive', 'isInternalOnly', 'dnsIpRotates', 'isPriority', 'domainExpiresAt']);

        // Defaults come from Pengaturan > Konfigurasi Aplikasi rather than being
        // hard-coded here, so an operator can set them once for every new site.
        $defaults = app(AppSettings::class)->websiteDefaults();
        $this->checkIntervalMinutes = $defaults['check_interval_minutes'];
        $this->downThresholdFailures = $defaults['down_threshold_failures'];
        $this->responseTimeThresholdMs = $defaults['response_time_threshold_ms'];
        $this->isActive = true;
        $this->showModal = true;
    }

    public function openEdit(Website $website): void
    {
        $this->authorize('situs.update');

        $this->editing = $website;
        $this->name = $website->name;
        $this->url = $website->url;
        $this->checkIntervalMinutes = $website->check_interval_minutes;
        $this->downThresholdFailures = $website->down_threshold_failures;
        $this->responseTimeThresholdMs = $website->response_time_threshold_ms;
        $this->isActive = $website->is_active;
        $this->isInternalOnly = $website->is_internal_only;
        $this->dnsIpRotates = (bool) $website->dns_ip_rotates;
        $this->isPriority = (bool) $website->is_priority;
        $this->domainExpiresAt = $website->domain_expires_at?->format('Y-m-d') ?? '';
        $this->showModal = true;
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'url' => [
                'required', 'url', 'max:255',
                // Shape alone is not enough: an http://10.0.0.5 would otherwise turn
                // this monitor into a proxy into its own network.
                new PublicUrl,
                Rule::unique('websites', 'url')->ignore($this->editing?->id),
            ],
            'checkIntervalMinutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'downThresholdFailures' => ['required', 'integer', 'min:1', 'max:20'],
            'responseTimeThresholdMs' => ['required', 'integer', 'min:200', 'max:25000'],
            'isActive' => ['boolean'],
            'isInternalOnly' => ['boolean'],
            'dnsIpRotates' => ['boolean'],
            'isPriority' => ['boolean'],
            'domainExpiresAt' => ['nullable', 'date'],
        ];
    }

    public function save(): void
    {
        $this->authorize($this->editing ? 'situs.update' : 'situs.create');

        // Auto-fill https:// when no scheme was typed at all; an explicit http://
        // or https:// the user did type is always left as-is.
        $this->url = SiteUrl::withScheme($this->url);

        $data = $this->validate();

        $website = $this->editing ?? new Website;
        $website->fill([
            'name' => $data['name'],
            'url' => rtrim($data['url'], '/'),
            'check_interval_minutes' => $data['checkIntervalMinutes'],
            'down_threshold_failures' => $data['downThresholdFailures'],
            'response_time_threshold_ms' => $data['responseTimeThresholdMs'],
            'is_active' => $data['isActive'],
            'is_internal_only' => $data['isInternalOnly'],
            'dns_ip_rotates' => $data['dnsIpRotates'],
            'is_priority' => $data['isPriority'],
            'domain_expires_at' => $data['domainExpiresAt'] ?: null,
        ]);

        // Only a date the operator actually changed is "manual" - re-saving an RDAP date untouched keeps its source.
        if (! $data['domainExpiresAt']) {
            $website->domain_expiry_source = null;
        } elseif ($website->isDirty('domain_expires_at')) {
            $website->domain_expiry_source = 'manual';
        }

        $website->save();

        $this->showModal = false;
        $this->dispatch('website-saved');
    }

    public function delete(Website $website): void
    {
        $this->authorize('situs.delete');

        $website->delete();
    }

    /**
     * Wipes every website - cascades (DB foreign keys) to its checks and incidents too. Bound to both "Reset" and "Hapus Data" in the view.
     */
    public function deleteAll(): void
    {
        $this->authorize('situs.delete');

        Website::query()->delete();

        $this->resetPage();
        $this->toastSuccess('Semua data situs (beserta riwayat cek dan insidennya) berhasil dihapus.');
    }

    public function openBulk(): void
    {
        $this->authorize('situs.create');

        $this->reset(['bulkInput', 'bulkResult']);
        $this->showBulkModal = true;
    }

    /**
     * Creates one website per non-empty line of $bulkInput. Each line is either
     * "Nama, https://url.com" (name/URL separated by a comma, pipe, semicolon, or
     * tab - so pasting straight from a spreadsheet column works too) or just a bare
     * URL, in which case the name is derived from its host. Uses the same defaults
     * as openCreate() - individual sites can be tuned afterwards via "Ubah".
     */
    public function bulkImport(): void
    {
        $this->authorize('situs.create');

        $this->validate(['bulkInput' => ['required', 'string']]);

        $lines = collect(preg_split('/\r\n|\r|\n/', $this->bulkInput))
            ->map(fn (string $line) => trim($line))
            ->filter()
            ->values();

        $created = 0;
        $skipped = [];
        $invalid = [];
        $seen = [];

        foreach ($lines as $line) {
            [$name, $url] = $this->parseBulkLine($line);
            $url = $url ? rtrim($url, '/') : null;

            // Same gate as the single-site form - bulk import is not a way around it.
            if (! $url || strlen($url) > 255 || ! filter_var($url, FILTER_VALIDATE_URL) || ! OutboundUrlGuard::allows($url)) {
                $invalid[] = $line;

                continue;
            }

            $urlKey = Str::lower($url);

            if (isset($seen[$urlKey]) || Website::where('url', $url)->exists()) {
                $skipped[] = $url;

                continue;
            }

            $seen[$urlKey] = true;

            $host = parse_url($url, PHP_URL_HOST) ?? $url;
            $name = $name ?: preg_replace('/^www\./i', '', $host);

            Website::create([
                'name' => Str::limit($name, 255, ''),
                'url' => $url,
                'is_active' => true,
                ...app(AppSettings::class)->websiteDefaults(),
            ]);

            $created++;
        }

        $this->bulkInput = '';
        $this->bulkResult = ['created' => $created, 'skipped' => $skipped, 'invalid' => $invalid];

        if ($created > 0) {
            $this->dispatch('website-saved');
        }
    }

    /**
     * @return array{0: ?string, 1: ?string} [name, url] - name is null when the line
     *                                       didn't specify one (derived from the URL's host by the caller instead).
     *                                       The URL is https://-normalized the same way as the single-add form.
     */
    protected function parseBulkLine(string $line): array
    {
        if (preg_match('/^(.*?)\s*[,|;\t]\s*(\S+)\s*$/', $line, $m)) {
            return [trim($m[1]) ?: null, SiteUrl::withScheme($m[2])];
        }

        return [null, SiteUrl::withScheme($line)];
    }

    // --- Mass edit -----------------------------------------------------------

    /**
     * uuids of the rows ticked in the list. Livewire round-trips this as strings,
     * so it stays a string array and is resolved to models only when applied.
     *
     * @var array<int, string>
     */
    public array $selected = [];

    public bool $showMassModal = false;

    /**
     * Which fields the mass edit is allowed to touch. Every field is opt-in, so a
     * setting deliberately tuned for one site is never flattened by a bulk change
     * aimed at a different setting entirely.
     *
     * @var array<string, bool>
     */
    public array $massApply = [
        'checkIntervalMinutes' => false,
        'downThresholdFailures' => false,
        'responseTimeThresholdMs' => false,
        'isActive' => false,
        'isInternalOnly' => false,
        'isPriority' => false,
    ];

    public int $massCheckIntervalMinutes = 5;

    public int $massDownThresholdFailures = 3;

    public int $massResponseTimeThresholdMs = 15000;

    public bool $massIsActive = true;

    public bool $massIsInternalOnly = false;

    public bool $massIsPriority = false;

    /** The mass-edit form field for each toggle, and the column it writes to. */
    protected const MASS_FIELDS = [
        'checkIntervalMinutes' => ['massCheckIntervalMinutes', 'check_interval_minutes'],
        'downThresholdFailures' => ['massDownThresholdFailures', 'down_threshold_failures'],
        'responseTimeThresholdMs' => ['massResponseTimeThresholdMs', 'response_time_threshold_ms'],
        'isActive' => ['massIsActive', 'is_active'],
        'isInternalOnly' => ['massIsInternalOnly', 'is_internal_only'],
        'isPriority' => ['massIsPriority', 'is_priority'],
    ];

    /** Upper bound on sites one mass edit may touch. */
    protected const MASS_MAX_SITES = 1000;

    /** Ticking the header box selects every site the current filter matches. */
    public function toggleSelectAll(bool $checked): void
    {
        $this->selected = $checked ? $this->filteredQuery()->pluck('uuid')->all() : [];
    }

    public function openMassEdit(): void
    {
        $this->authorize('situs.update');

        if ($this->selected === []) {
            return;
        }

        // Seeded from the app defaults so the numbers on screen are sensible ones
        // to apply, not whatever the last edited site happened to use.
        $defaults = app(AppSettings::class)->websiteDefaults();
        $this->massCheckIntervalMinutes = $defaults['check_interval_minutes'];
        $this->massDownThresholdFailures = $defaults['down_threshold_failures'];
        $this->massResponseTimeThresholdMs = $defaults['response_time_threshold_ms'];

        $this->reset('massApply');
        $this->resetValidation();
        $this->showMassModal = true;
    }

    /**
     * Applies only the ticked fields to the selected sites.
     *
     * Validated per field rather than wholesale: a value for a field nobody ticked
     * must not be able to block the save, and the rules are the same ones the
     * single-site form enforces so a bulk change cannot smuggle in an interval the
     * normal form would reject.
     */
    public function massSave(): void
    {
        $this->authorize('situs.update');

        // Unknown keys from the client are ignored instead of hitting an undefined MASS_FIELDS index.
        $applying = array_keys(array_filter(array_intersect_key($this->massApply, self::MASS_FIELDS)));

        // $selected comes from the client: strings only, and capped so one request cannot bloat the audit line.
        $selected = array_values(array_filter($this->selected, 'is_string'));

        if ($applying === [] || $selected === []) {
            $this->addError('massApply', 'Pilih minimal satu setelan yang ingin diubah.');

            return;
        }

        if (count($selected) > self::MASS_MAX_SITES) {
            $this->addError('massApply', 'Edit massal maksimal '.self::MASS_MAX_SITES.' situs sekaligus.');

            return;
        }

        $single = $this->rules();
        $rules = [];

        foreach ($applying as $field) {
            [$property] = self::MASS_FIELDS[$field];
            $rules[$property] = $single[$field];
        }

        $this->validate($rules);

        $changes = [];

        foreach ($applying as $field) {
            [$property, $column] = self::MASS_FIELDS[$field];
            $changes[$column] = $this->{$property};
        }

        // Only sites that really exist are updated and named in the audit line.
        $uuids = Website::whereIn('uuid', $selected)->pluck('uuid')->all();
        $count = Website::whereIn('uuid', $uuids)->update($changes);

        // The bulk update bypasses the Auditable model events, so log it as one line.
        AuditLog::record('situs.diubah-massal', "{$count} situs", $changes + ['situs' => $uuids]);

        $this->showMassModal = false;
        $this->selected = [];

        $this->toastSuccess('Setelan '.count($applying)." field berhasil diterapkan ke {$count} situs.");
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
        $this->selected = [];
    }

    public function updatingActivity(): void
    {
        $this->resetPage();
        $this->selected = [];
    }

    public function updatingCategory(): void
    {
        $this->resetPage();
        $this->selected = [];
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'status', 'activity', 'category');
        $this->resetPage();
        $this->selected = [];
    }

    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->status !== '' || $this->activity !== '' || $this->categoryFilter() !== null;
    }

    /** is_priority value for the category filter; null for '' or any unknown value from the URL. */
    protected function categoryFilter(): ?bool
    {
        return match ($this->category) {
            'priority' => true,
            'secondary' => false,
            default => null,
        };
    }

    /** The list the current filters match, without pagination. */
    protected function filteredQuery()
    {
        return Website::query()
            ->when($this->search, fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$this->search}%")
                ->orWhere('url', 'like', "%{$this->search}%")))
            ->when($this->activity !== '', fn ($q) => $q->where('is_active', $this->activity === 'active'))
            ->when($this->categoryFilter() !== null, fn ($q) => $q->where('is_priority', $this->categoryFilter()))
            ->when($this->status !== '', fn ($q) => $q->whereIn('id', $this->idsMatchingStatus()));
    }

    /**
     * Websites whose displayed status matches the filter.
     *
     * statusKey() reads open incidents rather than a column, so this cannot be a
     * WHERE clause - the keys are resolved for every site first, then fed back as
     * an id list so pagination still counts the right rows.
     *
     * @return array<int, int>
     */
    protected function idsMatchingStatus(): array
    {
        $websites = Website::query()
            ->when($this->search, fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$this->search}%")
                ->orWhere('url', 'like', "%{$this->search}%")))
            ->when($this->activity !== '', fn ($q) => $q->where('is_active', $this->activity === 'active'))
            ->when($this->categoryFilter() !== null, fn ($q) => $q->where('is_priority', $this->categoryFilter()))
            ->get();

        $offline = Website::offlineIds($websites);
        $attacked = Website::attackedIds($websites);
        $maintenance = Website::maintenanceIds($websites);

        return $websites
            ->filter(fn (Website $site) => $site->statusKey(
                isset($offline[$site->id]),
                isset($attacked[$site->id]),
                isset($maintenance[$site->id]),
            ) === $this->status)
            ->pluck('id')
            ->all();
    }

    public function checkNow(Website $website): void
    {
        $this->authorize('situs.update');

        $job = new CheckWebsiteJob($website);

        // Take the unique lock here so a dropped duplicate can be reported; Bus::dispatch skips the second acquire.
        $lock = new UniqueLock(app(Cache::class));

        if (! $lock->acquire($job)) {
            $this->toastInfo("Pengecekan {$website->name} sudah dalam antrean.");

            return;
        }

        try {
            Bus::dispatch($job);
        } catch (Throwable $e) {
            // Otherwise the button stays "already queued" until uniqueFor expires.
            $lock->release($job);

            throw $e;
        }

        $this->toastSuccess("Pengecekan {$website->name} dikirim ke antrean.");
    }

    /**
     * 7-day uptime per site on the page in one query; same semantics as Website::uptimePercentage().
     *
     * @return array<int, ?float>
     */
    protected function uptimes(LengthAwarePaginator $websites, int $days = 7): array
    {
        $ids = $websites->getCollection()->pluck('id');

        if ($ids->isEmpty()) {
            return [];
        }

        $rows = WebsiteCheck::query()
            ->whereIn('website_id', $ids)
            ->where('checked_at', '>=', now()->subDays($days))
            ->groupBy('website_id')
            ->toBase()
            ->selectRaw('website_id, COUNT(*) AS total, SUM(CASE WHEN '.WebsiteCheck::UP_SQL.' THEN 1 ELSE 0 END) AS up_count')
            ->get()
            ->keyBy('website_id');

        // Sites without checks in the window stay null, like uptimePercentage().
        return $ids->mapWithKeys(fn (int $id) => [$id => isset($rows[$id]) && (int) $rows[$id]->total > 0
            ? round((int) $rows[$id]->up_count / (int) $rows[$id]->total * 100, 1)
            : null])->all();
    }

    public function render()
    {
        // Same filter "select all" uses, so the box can never tick a different set
        // of sites than the one on screen.
        $websites = $this->filteredQuery()
            // See Website::scopeOrderForList().
            ->orderForList()
            // Counted in the page query itself, so the row badges add no per-row queries.
            ->withCount([
                'incidents as open_incidents_count' => fn ($q) => $q->open(),
            ])
            ->paginate(10);

        return view('livewire.websites.index', [
            'websites' => $websites,
            // One query each for the whole page instead of one per row - see Website::offlineIds().
            'offlineIds' => Website::offlineIds($websites),
            'attackedIds' => Website::attackedIds($websites),
            'maintenanceIds' => Website::maintenanceIds($websites),
            'loadLevels' => Website::loadLevels($websites),
            'uptimes' => $this->uptimes($websites),
            'filteredCount' => $this->filteredQuery()->count(),
        ]);
    }
}
