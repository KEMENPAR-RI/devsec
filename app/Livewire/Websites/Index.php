<?php

namespace App\Livewire\Websites;

use App\Jobs\CheckWebsiteJob;
use App\Models\Website;
use App\Services\AppSettings;
use App\Support\Url as SiteUrl;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
class Index extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    public bool $showModal = false;

    public ?Website $editing = null;

    public string $name = '';

    public string $url = '';

    public int $checkIntervalMinutes = 5;

    public int $judolScanIntervalMinutes = 60;

    public int $downThresholdFailures = 3;

    public int $responseTimeThresholdMs = 15000;

    public bool $isActive = true;

    public bool $isInternalOnly = false;

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
    }

    public function openCreate(): void
    {
        $this->authorize('situs.create');

        $this->reset(['editing', 'name', 'url', 'checkIntervalMinutes', 'judolScanIntervalMinutes', 'downThresholdFailures', 'responseTimeThresholdMs', 'isActive', 'isInternalOnly']);

        // Defaults come from Pengaturan > Konfigurasi Aplikasi rather than being
        // hard-coded here, so an operator can set them once for every new site.
        $defaults = app(AppSettings::class)->websiteDefaults();
        $this->checkIntervalMinutes = $defaults['check_interval_minutes'];
        $this->judolScanIntervalMinutes = $defaults['judol_scan_interval_minutes'];
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
        $this->judolScanIntervalMinutes = $website->judol_scan_interval_minutes;
        $this->downThresholdFailures = $website->down_threshold_failures;
        $this->responseTimeThresholdMs = $website->response_time_threshold_ms;
        $this->isActive = $website->is_active;
        $this->isInternalOnly = $website->is_internal_only;
        $this->showModal = true;
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'url' => [
                'required', 'url', 'max:255',
                Rule::unique('websites', 'url')->ignore($this->editing?->id),
            ],
            'checkIntervalMinutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'judolScanIntervalMinutes' => ['required', 'integer', 'min:5', 'max:10080'],
            'downThresholdFailures' => ['required', 'integer', 'min:1', 'max:20'],
            'responseTimeThresholdMs' => ['required', 'integer', 'min:200', 'max:60000'],
            'isActive' => ['boolean'],
            'isInternalOnly' => ['boolean'],
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
            'judol_scan_interval_minutes' => $data['judolScanIntervalMinutes'],
            'down_threshold_failures' => $data['downThresholdFailures'],
            'response_time_threshold_ms' => $data['responseTimeThresholdMs'],
            'is_active' => $data['isActive'],
            'is_internal_only' => $data['isInternalOnly'],
        ]);
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
     * Wipes every website - cascades (DB foreign keys) to its checks, incidents,
     * and judol detections too. Bound to both "Reset" and "Hapus Data" in the view.
     */
    public function deleteAll(): void
    {
        $this->authorize('situs.delete');

        Website::query()->delete();

        $this->resetPage();
        session()->flash('status', 'Semua data situs (beserta riwayat cek, insiden, dan deteksi judolnya) berhasil dihapus.');
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

            if (! $url || strlen($url) > 255 || ! filter_var($url, FILTER_VALIDATE_URL)) {
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

    public function checkNow(Website $website): void
    {
        $this->authorize('situs.update');

        CheckWebsiteJob::dispatch($website);
        session()->flash('status', "Pengecekan {$website->name} dikirim ke antrean.");
    }

    public function render()
    {
        $websites = Website::query()
            ->when($this->search, fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$this->search}%")
                ->orWhere('url', 'like', "%{$this->search}%")))
            // Newest first: a freshly added site is the one being checked on.
            ->latest()
            ->paginate(10);

        return view('livewire.websites.index', [
            'websites' => $websites,
            // One query for the whole page instead of one per row - see Website::offlineIds().
            'offlineIds' => Website::offlineIds($websites),
        ]);
    }
}
