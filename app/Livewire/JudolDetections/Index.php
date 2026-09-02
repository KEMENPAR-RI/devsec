<?php

namespace App\Livewire\JudolDetections;

use App\Models\JudolDetection;
use App\Models\JudolKeyword;
use App\Models\Setting;
use App\Models\Website;
use App\Services\Monitoring\JudolScanner;
use App\Support\DailyGroups;
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

    public int $threshold = 1;

    /** @var array<int, string> per-detection recheck() error, keyed by detection id */
    public array $recheckErrors = [];

    public function mount(): void
    {
        $this->authorize('deteksi-judol.view');

        $this->threshold = (int) (Setting::get('judol_threshold') ?? config('judol.threshold', 1));
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
            'websites' => Website::whereIn('id', $groups->pluck('site_key'))->get()->keyBy('id'),
            'keywords' => JudolKeyword::orderBy('keyword')->get(),
        ]);
    }
}
