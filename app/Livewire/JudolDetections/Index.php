<?php

namespace App\Livewire\JudolDetections;

use App\Jobs\PruneStaleJudolJob;
use App\Livewire\Concerns\InteractsWithToasts;
use App\Models\Incident;
use App\Models\JudolDetection;
use App\Models\JudolExclusion;
use App\Models\JudolFinding;
use App\Models\JudolKeyword;
use App\Models\Setting;
use App\Services\AppSettings;
use App\Services\Monitoring\JudolScanner;
use App\Services\Monitoring\TextNormalizer;
use App\Support\QueueHealth;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

#[Layout('components.layouts.app')]
class Index extends Component
{
    use InteractsWithToasts;

    #[Url]
    public string $search = '';

    public string $newKeyword = '';

    /** 3 = Kuat, 2 = Sedang, 1 = Lemah. */
    public int $newKeywordWeight = 3;

    public string $newExclusion = '';

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

    /** Minimum weighted score for a page to count as a finding. */
    public int $threshold = 3;

    /** @var array<string, string> per-finding recheckUrl() error, keyed by finding uuid */
    public array $recheckErrors = [];

    /** Which finding's affected-URL list is expanded; null when all are collapsed. */
    public ?string $expandedFinding = null;

    public function mount(): void
    {
        $this->authorize('deteksi-judol.view');

        $this->threshold = app(AppSettings::class)->judolScoreThreshold();
    }

    /**
     * Triage for an injection site - the only unit this page acts on now. Marking
     * one settles every page that renders the same shared markup at once, which is
     * the whole reason findings exist: cleaning the footer cleans all forty pages.
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

    public function deleteFinding(JudolFinding $finding): void
    {
        $this->authorize('deteksi-judol.delete');

        $finding->delete();

        $this->toastSuccess('Titik penyisipan dihapus.');
    }

    public function toggleFinding(string $uuid): void
    {
        $this->expandedFinding = $this->expandedFinding === $uuid ? null : $uuid;
    }

    /**
     * Force-re-evaluates one affected URL against the current keyword list - e.g.
     * after removing a too-generic keyword that caused a false positive. If the
     * page no longer matches, JudolScanner removes the stale detection itself (see
     * clearStaleDetection()); this just surfaces the outcome.
     *
     * Addressed by INDEX into the finding's stored affected_urls rather than by a
     * URL from the request, so this button can only ever re-fetch an address the
     * crawler already recorded - it must not become a way to make the server fetch
     * an arbitrary URL.
     */
    public function recheckUrl(JudolFinding $finding, int $index): void
    {
        $this->authorize('deteksi-judol.update');

        unset($this->recheckErrors[$finding->uuid]);

        $url = ($finding->affected_urls ?? [])[$index] ?? null;
        $website = $finding->website;

        if (! $url || ! $website) {
            return;
        }

        try {
            $matched = app(JudolScanner::class)->scanUrlNow($website, $url);

            $matched
                ? $this->toastWarning("{$url} masih cocok kata kunci: ".implode(', ', $matched).'.')
                : $this->toastSuccess("{$url} sudah bersih sekarang - deteksi lama dihapus.");
        } catch (Throwable $e) {
            $this->recheckErrors[$finding->uuid] = $e->getMessage();
            $this->toastError("Gagal memeriksa ulang {$url}: ".$e->getMessage());
        }
    }

    public function addKeyword(): void
    {
        $this->authorize('deteksi-judol.update');

        $this->validate([
            'newKeyword' => ['required', 'string', 'max:100', 'unique:judol_keywords,keyword'],
            'newKeywordWeight' => ['required', 'integer', 'in:1,2,3'],
        ], [], ['newKeyword' => 'kata kunci', 'newKeywordWeight' => 'bobot']);

        JudolKeyword::create(['keyword' => trim($this->newKeyword), 'weight' => $this->newKeywordWeight, 'is_active' => true]);

        $this->newKeyword = '';
        $this->toastSuccess('Kata kunci ditambahkan.');
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
            ['bulkKeywords' => ['required', 'string'], 'newKeywordWeight' => ['required', 'integer', 'in:1,2,3']],
            [],
            ['bulkKeywords' => 'daftar kata kunci', 'newKeywordWeight' => 'bobot']
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

            JudolKeyword::create(['keyword' => $keyword, 'weight' => $this->newKeywordWeight, 'is_active' => true]);
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

        // Deactivating prunes (deletes) detections.
        if ($keyword->is_active) {
            $this->authorize('deteksi-judol.delete');
        }

        $keyword->update(['is_active' => ! $keyword->is_active]);

        // Deactivating can only make matches disappear, never add new ones - prune
        // right away so stale detections don't linger. Reactivating needs no pruning.
        if (! $keyword->is_active) {
            $this->toastSuccess($this->pruneStale('Kata kunci dinonaktifkan.'));
        }
    }

    public function removeKeyword(JudolKeyword $keyword): void
    {
        $this->authorize('deteksi-judol.delete');

        $keyword->delete();

        $this->toastSuccess($this->pruneStale('Kata kunci dihapus.'));
    }

    public function setKeywordWeight(JudolKeyword $keyword, int $weight): void
    {
        $this->authorize('deteksi-judol.update');
        $this->authorize('deteksi-judol.delete');

        Validator::make(['weight' => $weight], ['weight' => ['required', 'integer', 'in:1,2,3']], [], ['weight' => 'bobot'])->validate();

        $keyword->update(['weight' => $weight]);

        $this->toastSuccess($this->pruneStale('Bobot kata kunci disimpan.'));
    }

    public function addExclusion(): void
    {
        $this->authorize('deteksi-judol.update');
        $this->authorize('deteksi-judol.delete');

        $this->newExclusion = trim($this->newExclusion);

        $this->validate([
            'newExclusion' => ['required', 'string', 'min:3', 'max:150', 'unique:judol_exclusions,phrase', function (string $attribute, mixed $value, Closure $fail) {
                // An exclusion equal to a keyword would switch that keyword off everywhere.
                $folded = TextNormalizer::foldKeyword((string) $value);

                if (JudolKeyword::query()->pluck('keyword')->contains(fn (string $keyword) => TextNormalizer::foldKeyword($keyword) === $folded)) {
                    $fail('Frasa pengecualian tidak boleh sama dengan kata kunci.');
                }
            }],
        ], [], ['newExclusion' => 'frasa pengecualian']);

        JudolExclusion::create(['phrase' => $this->newExclusion, 'is_active' => true]);

        $this->newExclusion = '';
        $this->toastSuccess($this->pruneStale('Pengecualian frasa ditambahkan.', recheckSnapshots: true));
    }

    public function toggleExclusion(JudolExclusion $exclusion): void
    {
        $this->authorize('deteksi-judol.update');

        // Activating prunes (deletes) detections.
        if (! $exclusion->is_active) {
            $this->authorize('deteksi-judol.delete');
        }

        $exclusion->update(['is_active' => ! $exclusion->is_active]);

        // Activating can only remove matches; deactivating needs no pruning.
        $exclusion->is_active
            ? $this->toastSuccess($this->pruneStale('Pengecualian diaktifkan.', recheckSnapshots: true))
            : $this->toastSuccess('Pengecualian dinonaktifkan.');
    }

    public function removeExclusion(JudolExclusion $exclusion): void
    {
        $this->authorize('deteksi-judol.delete');

        $exclusion->delete();

        $this->toastSuccess('Pengecualian dihapus.');
    }

    /**
     * Clears out what the removed keyword was holding up, on both sides.
     *
     * Findings are pruned as well as detections because findings are what this page
     * shows: without it, striking a false-positive phrase off the list would leave
     * the injection site that rested on it sitting at the top of the results.
     */
    protected function pruneStale(string $message, bool $recheckSnapshots = false): string
    {
        // Weight/threshold edits only need the stored weights; re-reading every
        // stored page is reserved for a new/activated exclusion.
        $scanner = app(JudolScanner::class);

        // Too many stored pages to re-read in one request: weights now, snapshots on the queue.
        $queued = $recheckSnapshots && JudolDetection::whereNotNull('matched_keywords')->whereNotNull('content_snapshot')
            ->count() > (int) config('monitoring.judol_recheck_sync_limit', 300);

        $cleared = $scanner->pruneStaleDetections(recheckSnapshots: $recheckSnapshots && ! $queued) + $scanner->pruneStaleFindings();
        $scanner->auditPrune($message);

        if ($queued) {
            PruneStaleJudolJob::dispatch($message, auth()->id());
        }

        $message = $cleared > 0
            ? "{$message} {$cleared} temuan yang sudah tidak valid ikut dibersihkan."
            : $message;

        return $queued ? "{$message} Pemeriksaan ulang isi halaman dilanjutkan di antrean." : $message;
    }

    public function saveThreshold(): void
    {
        $this->authorize('deteksi-judol.update');
        $this->authorize('deteksi-judol.delete');

        $this->validate(['threshold' => ['required', 'integer', 'min:1', 'max:50']], [], ['threshold' => 'ambang skor']);

        Setting::set('judol_score_threshold', (string) $this->threshold);
        $this->toastSuccess($this->pruneStale('Ambang skor disimpan.'));
    }

    /**
     * Wipes every judol result - detections AND injection sites. The keyword list
     * (App\Models\JudolKeyword) is untouched. Bound to "Hapus Data" in the view.
     */
    public function deleteAll(): void
    {
        $this->authorize('deteksi-judol.delete');

        // Resolved before the rows go, not after: an open judol incident whose
        // detection has been deleted points at nothing, and the Insiden page would
        // keep showing it forever with no way left to close it.
        Incident::where('type', 'judol')->where('status', 'open')->each(fn (Incident $incident) => $incident->resolve());

        JudolDetection::query()->delete();
        JudolFinding::query()->delete();

        $this->toastSuccess('Semua data deteksi judol berhasil dihapus. Kata kunci tetap tersimpan.');
    }

    /**
     * Injection sites, filtered, with the ignored ones dropped - they are a
     * decision already made.
     */
    protected function findings()
    {
        $search = trim($this->search);

        return JudolFinding::with('website')
            ->where('status', '!=', 'ignored')
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('region_label', 'like', "%{$search}%")
                ->orWhere('region_selector', 'like', "%{$search}%")
                // The JSON columns are searched as raw text on purpose: the operator
                // is looking for a domain or a phrase, and "does this blob contain
                // it" is exactly that question. A JSON path query would buy
                // precision nobody is asking for here.
                ->orWhere('linked_domains', 'like', "%{$search}%")
                ->orWhere('matched_keywords', 'like', "%{$search}%")
                ->orWhere('affected_urls', 'like', "%{$search}%")
                ->orWhereHas('website', fn ($q) => $q->where('name', 'like', "%{$search}%"))))
            ->orderByDesc('affected_count')
            ->orderByDesc('last_seen_at')
            ->get();
    }

    /**
     * Findings collapsed into one entry per distinct piece of evidence.
     *
     * One edit by an attacker routinely lands in several regions at once - the same
     * link block pasted into the footer AND the sidebar. Those stay separate rows
     * in the database, correctly, because each is a separate place to go and clean.
     * But they are ONE finding, and listing them twice repeats the whole body of
     * evidence twice. So the evidence is stated once, and the regions hang under it
     * as the list of places it was found.
     *
     * @param  Collection<int, JudolFinding>  $findings
     * @return Collection<int, array<string, mixed>>
     */
    protected function groupByEvidence($findings)
    {
        $weights = array_change_key_case(JudolKeyword::activeRules(), CASE_LOWER);

        return $findings
            ->groupBy(fn (JudolFinding $finding) => $finding->website_id.'|'.$finding->evidenceSignature())
            ->map(function ($group) use ($weights) {
                /** @var JudolFinding $first */
                $first = $group->first();

                // Unioned, not summed: the same page carries both the footer and the
                // sidebar, so adding the counts would report it twice. Each URL keeps
                // the finding and position it came from, because "Verifikasi Ulang"
                // addresses a page by (finding, index) rather than by URL.
                $urls = [];

                // Keyed by the image URL for the same reason: the same banner sits
                // in the footer and the sidebar, and showing it twice is the
                // duplication this grouping exists to remove. The (finding, index)
                // pair it keeps is the one the proxy route addresses it by, so it
                // has to be the position in THAT finding's own list.
                $images = [];

                foreach ($group as $finding) {
                    foreach ($finding->affected_urls ?? [] as $index => $url) {
                        $urls[$url] ??= ['url' => $url, 'finding' => $finding, 'index' => $index];
                    }

                    foreach ($finding->image_urls ?? [] as $index => $image) {
                        $images[$image] ??= ['source' => $image, 'finding' => $finding, 'index' => $index];
                    }
                }

                $urls = array_values($urls);
                $keywords = $group->flatMap(fn (JudolFinding $f) => $f->matched_keywords ?? [])->unique()->values()->all();

                // Score from the current weights (cached rules, no query); a keyword
                // counts as hidden when any of its evidence nodes was.
                $hidden = $group->contains(fn (JudolFinding $f) => collect($f->evidence_nodes ?? [])->contains(fn ($node) => ! empty($node['hidden'])));
                $matchDetails = collect($keywords)->map(fn (string $keyword) => [
                    'keyword' => $keyword,
                    'weight' => $weights[Str::lower($keyword)] ?? 0,
                    'sources' => [],
                    'hidden' => $hidden,
                ])->all();

                return [
                    'key' => $first->website_id.'|'.$first->evidenceSignature(),
                    'website' => $first->website,
                    'attack_label' => $first->attackLabel(),
                    'domains' => $group->flatMap(fn (JudolFinding $f) => $f->linked_domains ?? [])->unique()->values()->all(),
                    'keywords' => $keywords,
                    'score' => array_sum(array_column($matchDetails, 'weight')),
                    'match_details' => $matchDetails,
                    'anchors' => $group->flatMap(fn (JudolFinding $f) => $f->anchor_texts ?? [])->filter()->unique()->values()->all(),
                    'images' => array_slice(array_values($images), 0, 8),
                    'findings' => $group->values(),
                    'urls' => $urls,
                    // The stored URL lists are capped per finding, so the real total
                    // can be higher than what was unioned above.
                    'url_count' => max(count($urls), (int) $group->max('affected_count')),
                    'status' => $group->contains(fn (JudolFinding $f) => $f->status === 'new') ? 'new' : 'reviewed',
                    'first_seen_at' => $group->min('first_seen_at'),
                    'last_seen_at' => $group->max('last_seen_at'),
                ];
            })
            ->sortByDesc('url_count')
            ->values();
    }

    public function render()
    {
        return view('livewire.judol-detections.index', [
            'groups' => $this->groupByEvidence($this->findings()),
            'keywords' => JudolKeyword::orderBy('keyword')->get(),
            'exclusions' => JudolExclusion::orderBy('phrase')->get(),
            // The automatic crawl runs as a queued job, so with no worker on the
            // server it never happens - while manual checks, which run inside the
            // request, keep working. Surfaced here because this is the page where
            // someone notices results have stopped arriving.
            'queueStalled' => app(QueueHealth::class)->isStalled(),
            'queuePending' => app(QueueHealth::class)->pending(),
        ]);
    }
}
