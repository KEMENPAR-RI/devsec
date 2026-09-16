<?php

namespace App\Livewire\Websites;

use App\Jobs\CheckWebsiteJob;
use App\Jobs\ScanWebsiteForJudolJob;
use App\Models\MaintenanceWindow;
use App\Models\Website;
use App\Rules\PublicUrl;
use App\Services\Monitoring\JudolScanner;
use App\Services\Monitoring\SecurityGrader;
use App\Support\Dates;
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

    // --- Maintenance window form ------------------------------------------

    public bool $showMaintenanceForm = false;

    public string $maintenanceReason = '';

    /** Datetime-local values, read in the app timezone rather than UTC. */
    public string $maintenanceStartsAt = '';

    public string $maintenanceEndsAt = '';

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

        $this->validate(['manualScanUrl' => ['required', 'url', 'max:2048', new PublicUrl]]);

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

    /**
     * Re-grades this one site now, instead of waiting for the nightly pass.
     *
     * Synchronous rather than queued: it is one request plus one handshake, and
     * the whole point of the button is seeing the new grade on the screen you
     * pressed it from.
     */
    public function gradeSecurityNow(): void
    {
        $this->authorize('situs.update');

        $result = app(SecurityGrader::class)->grade($this->website);

        if ($result === null) {
            session()->flash('status', 'Situs tidak merespons, jadi nilainya tidak diubah.');

            return;
        }

        $this->website->update([
            'security_score' => $result['score'],
            'security_grade' => $result['grade'],
            'security_findings' => $result['findings'],
            'security_checked_at' => now(),
        ]);

        session()->flash('status', "Penilaian keamanan diperbarui: {$result['grade']} ({$result['score']}/100).");
    }

    // --- Maintenance windows ----------------------------------------------

    public function openMaintenanceForm(): void
    {
        $this->authorize('situs.update');

        $this->resetErrorBag();
        // Prefilled with a plausible window rather than blank fields - the common
        // case is "starting about now, for an hour or two".
        $this->maintenanceReason = '';
        $this->maintenanceStartsAt = Dates::toLocalInput(now());
        $this->maintenanceEndsAt = Dates::toLocalInput(now()->addHours(2));
        $this->showMaintenanceForm = true;
    }

    public function saveMaintenance(): void
    {
        $this->authorize('situs.update');

        $data = $this->validate([
            'maintenanceReason' => ['required', 'string', 'max:255'],
            'maintenanceStartsAt' => ['required', 'date'],
            'maintenanceEndsAt' => ['required', 'date', 'after:maintenanceStartsAt'],
        ], [
            'maintenanceEndsAt.after' => 'Waktu selesai harus setelah waktu mulai.',
        ], [
            'maintenanceReason' => 'keterangan',
            'maintenanceStartsAt' => 'waktu mulai',
            'maintenanceEndsAt' => 'waktu selesai',
        ]);

        MaintenanceWindow::create([
            'website_id' => $this->website->id,
            'created_by' => auth()->id(),
            'reason' => $data['maintenanceReason'],
            // The form speaks the app's timezone; storage is UTC like every other
            // timestamp here, so the conversion happens once, on the way in.
            'starts_at' => Dates::fromLocalInput($data['maintenanceStartsAt']),
            'ends_at' => Dates::fromLocalInput($data['maintenanceEndsAt']),
        ]);

        $this->showMaintenanceForm = false;
        session()->flash('status', 'Jendela pemeliharaan disimpan. Insiden dan notifikasi untuk situs ini ditahan selama periode tersebut.');
    }

    public function deleteMaintenance(string $uuid): void
    {
        $this->authorize('situs.update');

        $this->website->maintenanceWindows()->where('uuid', $uuid)->first()?->delete();

        session()->flash('status', 'Jendela pemeliharaan dihapus.');
    }

    public function render()
    {
        $checks = $this->website->checks()->latest('checked_at')->limit(30)->get()->reverse()->values();

        return view('livewire.websites.show', [
            'checks' => $checks,
            'incidents' => $this->website->incidents()->latest('detected_at')->limit(10)->get(),
            'judolDetections' => $this->website->judolDetections()->latest('detected_at')->limit(10)->get(),
            // Includes the all-sites windows, since those cover this site too.
            'maintenanceWindows' => MaintenanceWindow::query()
                ->forWebsite($this->website->id)
                ->upcoming()
                ->orderBy('starts_at')
                ->with('author')
                ->get(),
            'underMaintenance' => $this->website->isUnderMaintenance(),
        ]);
    }
}
