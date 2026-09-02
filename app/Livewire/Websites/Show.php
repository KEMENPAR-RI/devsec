<?php

namespace App\Livewire\Websites;

use App\Jobs\CheckWebsiteJob;
use App\Jobs\ScanWebsiteForJudolJob;
use App\Models\Website;
use App\Services\Monitoring\JudolScanner;
use App\Support\Url as SiteUrl;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Throwable;

#[Layout('components.layouts.app')]
class Show extends Component
{
    public Website $website;

    public string $manualScanUrl = '';

    /** @var array{url: string, matched: array<int, string>, ai: ?array{label: string, tone: string, reasoning: ?string, confidence: ?float}}|null */
    public ?array $manualScanResult = null;

    public function mount(Website $website): void
    {
        $this->authorize('situs.view');

        $this->website = $website;
    }

    public function checkNow(): void
    {
        $this->authorize('situs.update');

        CheckWebsiteJob::dispatch($this->website);
        session()->flash('status', 'Pengecekan dikirim ke antrean.');
    }

    public function scanJudolNow(): void
    {
        $this->authorize('situs.update');

        ScanWebsiteForJudolJob::dispatch($this->website);
        session()->flash('status', 'Pemindaian judol (full crawl) dikirim ke antrean. Bisa makan waktu beberapa menit untuk situs besar.');
    }

    /**
     * Immediately fetches and checks one specific URL - useful to verify a page you
     * suspect (e.g. a stray slot.html) right away, without waiting for the full crawl.
     */
    public function scanSpecificUrl(): void
    {
        $this->authorize('situs.update');

        $this->manualScanResult = null;

        // Same convenience as the add-site form: a bare host is assumed https.
        $this->manualScanUrl = SiteUrl::withScheme($this->manualScanUrl);

        $this->validate(['manualScanUrl' => ['required', 'url', 'max:2048']]);

        try {
            $matched = app(JudolScanner::class)->scanUrlNow($this->website, $this->manualScanUrl);

            // The scan runs the AI classifier too (when enabled); surface its verdict
            // here rather than making the user go find the row on the judol page.
            $detection = $matched
                ? $this->website->judolDetections()->where('url', $this->manualScanUrl)->first()
                : null;

            $this->manualScanResult = [
                'url' => $this->manualScanUrl,
                'matched' => $matched,
                'ai' => $detection && $detection->ai_verdict ? [
                    'label' => $detection->aiBadge()['label'],
                    'tone' => $detection->aiBadge()['tone'],
                    'reasoning' => $detection->ai_reasoning,
                    'confidence' => $detection->ai_confidence,
                ] : null,
            ];
        } catch (Throwable $e) {
            $this->addError('manualScanUrl', $e->getMessage());
        }
    }

    public function render()
    {
        $checks = $this->website->checks()->latest('checked_at')->limit(30)->get()->reverse()->values();

        return view('livewire.websites.show', [
            'checks' => $checks,
            'incidents' => $this->website->incidents()->latest('detected_at')->limit(10)->get(),
            'judolDetections' => $this->website->judolDetections()->latest('detected_at')->limit(10)->get(),
        ]);
    }
}
