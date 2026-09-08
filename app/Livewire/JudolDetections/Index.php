<?php

namespace App\Livewire\JudolDetections;

use App\Models\JudolDetection;
use App\Models\JudolFinding;
use App\Models\JudolKeyword;
use App\Models\Setting;
use App\Models\Website;
use App\Services\AppSettings;
use App\Services\Monitoring\JudolScanner;
use App\Support\DailyGroups;
use App\Support\QueueHealth;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

#[Layout('components.layouts.app')]
class Index extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    /** '' | 'confirmed' | 'keyword' - lets the list separate AI-vetted findings. */
    #[Url]
    public string $verification = '';

    public string $newKeyword = '';

    public bool $showBulkKeywordModal = false;

    public string $bulkKeywords = '';

    /** @var array{added: int, duplicate: array<int, string>, invalid: array<int, string>}|null */
    public ?array $bulkKeywordResult = null;

    /** Matches the single-add form's rule, so both doors accept the same phrases. */
    protected const MAX_KEYWORD_LENGTH = 100;

    /**
     * Entries read from one paste. A real keyword list runs to a few dozen; this is
     * only here so a stray paste of an entire page cannot turn into thousands of
     * inserts, each of which the scanner then checks against every crawled page.
     */
    protected const MAX_BULK_KEYWORDS = 500;

    public int $threshold = 1;

    /** @var array<int, string> per-detection recheck() error, keyed by detection id */
    public array $recheckErrors = [];

    /** Which finding's affected-URL list is expanded; null when all are collapsed. */
    public ?string $expandedFinding = null;

    public function mount(): void
    {
        $this->authorize('deteksi-judol.view');

        $this->threshold = app(AppSettings::class)->judolThreshold();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingVerification(): void
    {
        $this->resetPage();
    }

    public function markReviewed(JudolDetection $detection): void
    {
        $this->authorize('deteksi-judol.update');

        $detection->update(['status' => 'reviewed']);
    }

    public function markIgnored(JudolDetection $detection): void
    {
        $this->authorize('deteksi-judol.update');

        $detection->update(['status' => 'ignored']);
    }

    /**
     * Triage for an injection site. Separate from the per-detection actions above
     * because the unit of work is different: acting on a finding means the shared
     * markup was cleaned, which settles every page that renders it at once.
     */
    public function markFindingReviewed(JudolFinding $finding): void
    {
        $this->authorize('deteksi-judol.update');

        $finding->update(['status' => 'reviewed']);
    }

    public function markFindingIgnored(JudolFinding $finding): void
    {
        $this->authorize('deteksi-judol.update');

        $finding->update(['status' => 'ignored']);
    }

    public function toggleFinding(string $uuid): void
    {
        $this->expandedFinding = $this->expandedFinding === $uuid ? null : $uuid;
    }

    /**
     * Force-re-evaluates one detection's URL against the current keyword list -
     * e.g. after removing a too-generic keyword that caused a false positive. If
     * the page no longer matches, JudolScanner removes the stale detection itself
     * (see clearStaleDetection()); this just surfaces the outcome to the user.
     */
    public function recheck(JudolDetection $detection): void
    {
        $this->authorize('deteksi-judol.update');

        unset($this->recheckErrors[$detection->id]);
        $website = $detection->website;
        $url = $detection->url;

        try {
            $matched = app(JudolScanner::class)->scanUrlNow($website, $url);

            session()->flash('status', $matched
                ? "{$url} masih cocok kata kunci: ".implode(', ', $matched).'.'
                : "{$url} sudah bersih sekarang - deteksi lama dihapus.");
        } catch (Throwable $e) {
            $this->recheckErrors[$detection->id] = $e->getMessage();
        }
    }

    public function addKeyword(): void
    {
        $this->authorize('deteksi-judol.update');

        $this->validate([
            'newKeyword' => ['required', 'string', 'max:100', 'unique:judol_keywords,keyword'],
        ], [], ['newKeyword' => 'kata kunci']);

        JudolKeyword::create(['keyword' => trim($this->newKeyword), 'is_active' => true]);

        $this->newKeyword = '';
        session()->flash('keyword-status', 'Kata kunci ditambahkan.');
    }

    public function openBulkKeywords(): void
    {
        $this->authorize('deteksi-judol.update');

        $this->reset(['bulkKeywords', 'bulkKeywordResult']);
        $this->showBulkKeywordModal = true;
    }

    /**
     * Adds many keywords at once.
     *
     * Split on newlines AND commas, because a new spam wave never arrives one
     * phrase at a time - it arrives as a list someone pasted out of a report, a
     * spreadsheet column, or straight out of config/judol.php with its quotes and
     * trailing commas still attached. Every keyword is a phrase without commas of
     * its own, so a comma can only ever be a separator here.
     */
    public function bulkAddKeywords(): void
    {
        $this->authorize('deteksi-judol.update');

        $this->validate(
            ['bulkKeywords' => ['required', 'string']],
            [],
            ['bulkKeywords' => 'daftar kata kunci']
        );

        // Compared in lower case, so a paste that differs only in capitalisation
        // from a keyword already on the list is recognised as the same one -
        // matching is case-insensitive anyway (see JudolScanner::evaluateAndStore).
        $existing = JudolKeyword::pluck('keyword')
            ->mapWithKeys(fn (string $keyword) => [Str::lower($keyword) => true])
            ->all();

        $added = 0;
        $duplicate = [];
        $invalid = [];

        foreach ($this->splitKeywords($this->bulkKeywords) as $keyword) {
            if ($keyword === '' || mb_strlen($keyword) > self::MAX_KEYWORD_LENGTH) {
                $invalid[] = Str::limit($keyword, 40);

                continue;
            }

            $key = Str::lower($keyword);

            if (isset($existing[$key])) {
                $duplicate[] = $keyword;

                continue;
            }

            // Marks it seen before the insert, so a list that repeats a phrase
            // reports the repeat rather than failing on the unique index.
            $existing[$key] = true;

            JudolKeyword::create(['keyword' => $keyword, 'is_active' => true]);
            $added++;
        }

        $this->bulkKeywords = '';
        $this->bulkKeywordResult = ['added' => $added, 'duplicate' => $duplicate, 'invalid' => $invalid];
    }

    /**
     * One trimmed keyword per entry, from a paste in whatever shape it arrived.
     *
     * Strips the punctuation a copied config line carries - surrounding quotes,
     * a leading list bullet - and squishes inner whitespace, so "  'slot  gacor',"
     * and "- Slot Gacor" both land as the phrase itself.
     *
     * @return array<int, string>
     */
    protected function splitKeywords(string $input): array
    {
        return collect(preg_split('/[\r\n,]+/', $input))
            ->map(fn (string $line) => Str::squish($line))
            ->map(fn (string $line) => trim($line, " \t\"'`[]();-•*"))
            ->map(fn (string $line) => Str::squish($line))
            ->filter()
            ->take(self::MAX_BULK_KEYWORDS)
            ->values()
            ->all();
    }

    public function toggleKeyword(JudolKeyword $keyword): void
    {
        $this->authorize('deteksi-judol.update');

        $keyword->update(['is_active' => ! $keyword->is_active]);

        // Deactivating can only make matches disappear, never add new ones - prune
        // right away so stale detections don't linger. Reactivating needs no pruning.
        if (! $keyword->is_active) {
            $cleared = app(JudolScanner::class)->pruneStaleDetections();
            session()->flash('keyword-status', $cleared > 0
                ? "Kata kunci dinonaktifkan. {$cleared} deteksi yang sudah tidak valid ikut dibersihkan."
                : 'Kata kunci dinonaktifkan.');
        }
    }

    public function removeKeyword(JudolKeyword $keyword): void
    {
        $this->authorize('deteksi-judol.delete');

        $keyword->delete();

        $cleared = app(JudolScanner::class)->pruneStaleDetections();
        session()->flash('keyword-status', $cleared > 0
            ? "Kata kunci dihapus. {$cleared} deteksi yang sudah tidak valid ikut dibersihkan."
            : 'Kata kunci dihapus.');
    }

    public function saveThreshold(): void
    {
        $this->authorize('deteksi-judol.update');

        $this->validate(['threshold' => ['required', 'integer', 'min:1', 'max:20']]);

        Setting::set('judol_threshold', (string) $this->threshold);
        session()->flash('keyword-status', 'Ambang batas disimpan.');
    }

    /**
     * Wipes every judol detection - the keyword list (App\Models\JudolKeyword) is
     * untouched. Bound to both "Reset" and "Hapus Data" in the view.
     */
    public function delete(JudolDetection $detection): void
    {
        $this->authorize('deteksi-judol.delete');

        // Resolve the incident it raised too, otherwise the Insiden page keeps an
        // open entry pointing at a finding that no longer exists.
        $detection->incident?->resolve();
        $detection->delete();

        session()->flash('status', 'Deteksi judol dihapus.');
    }

    public function deleteAll(): void
    {
        $this->authorize('deteksi-judol.delete');

        JudolDetection::query()->delete();

        $this->resetPage();
        session()->flash('status', 'Semua data deteksi judol berhasil dihapus. Kata kunci tetap tersimpan.');
    }

    /**
     * The filtered set, before it gets split into (site, day) groups.
     */
    protected function baseQuery()
    {
        return JudolDetection::query()
            ->when($this->search, fn ($q) => $q->where(fn ($q) => $q
                ->where('url', 'like', "%{$this->search}%")
                ->orWhereHas('website', fn ($q) => $q->where('name', 'like', "%{$this->search}%"))))
            // "keyword" covers everything the AI did not positively confirm: never
            // reviewed, unavailable, or reviewed and disputed.
            ->when($this->verification === 'confirmed', fn ($q) => $q->where('ai_verdict', 'confirmed'))
            ->when($this->verification === 'keyword', fn ($q) => $q->where(fn ($q) => $q
                ->whereNull('ai_verdict')
                ->orWhere('ai_verdict', '!=', 'confirmed')));
    }

    public function render()
    {
        ['groups' => $groups, 'rows' => $rows] = DailyGroups::forPage(
            query: fn () => $this->baseQuery(),
            dateColumn: 'detected_at',
            rowKey: fn (JudolDetection $detection) => DailyGroups::key($detection->website_id, $detection->detected_at->toDateString()),
        );

        return view('livewire.judol-detections.index', [
            'groups' => $groups,
            'detectionsByGroup' => $rows,
            // Injection sites, shown above the per-page list rather than mixed into
            // it: one of these stands for every page carrying the same shared
            // markup, so filing it among those pages would re-create exactly the
            // repetition it exists to collapse. Ignored ones drop out - they are a
            // decision already made.
            'findings' => JudolFinding::with('website')
                ->where('status', '!=', 'ignored')
                ->orderByDesc('affected_count')
                ->orderByDesc('last_seen_at')
                ->get(),
            'websites' => Website::whereIn('id', $groups->pluck('site_key'))->get()->keyBy('id'),
            'keywords' => JudolKeyword::orderBy('keyword')->get(),
            // The automatic crawl runs as a queued job, so with no worker on the
            // server it never happens - while manual checks, which run inside the
            // request, keep working. Surfaced here because this is the page where
            // someone notices results have stopped arriving.
            'queueStalled' => app(QueueHealth::class)->isStalled(),
            'queuePending' => app(QueueHealth::class)->pending(),
        ]);
    }
}
