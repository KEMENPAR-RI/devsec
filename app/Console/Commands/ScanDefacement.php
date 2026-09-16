<?php

namespace App\Console\Commands;

use App\Models\Incident;
use App\Models\User;
use App\Models\Website;
use App\Notifications\AttackAnomalyDetected;
use App\Services\Monitoring\DefacementScanner;
use App\Services\Notifications\MailSettings;
use App\Services\Notifications\WebhookNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Daily sweep for code on a homepage that nobody at the institution put there.
 *
 * Reuses the existing 'attack' incident type with its own reason rather than
 * inventing a parallel structure - it lands on the Insiden page, in the email
 * template, and in the chat channel with no new plumbing at all.
 */
class ScanDefacement extends Command
{
    protected $signature = 'websites:scan-defacement
        {--site= : Scan only the website with this uuid}';

    protected $description = 'Scan homepages for foreign scripts, hidden frames, and obfuscated code';

    public function handle(DefacementScanner $scanner): int
    {
        $websites = Website::query()
            ->where('is_active', true)
            ->when($this->option('site'), fn ($q) => $q->where('uuid', $this->option('site')))
            ->get();

        $flagged = 0;

        foreach ($websites as $website) {
            if ($website->isUnderMaintenance()) {
                continue;
            }

            try {
                $findings = $scanner->scan($website);
            } catch (Throwable $e) {
                $this->warn("{$website->name}: {$e->getMessage()}");

                continue;
            }

            if ($findings === []) {
                continue;
            }

            // One open incident per site at a time. The same injected loader is
            // still there tomorrow, and a fresh incident every night would bury
            // the site that just got hit.
            $alreadyOpen = $website->incidents()
                ->open()
                ->where('type', 'attack')
                ->whereJsonContains('meta->reason', 'foreign_script')
                ->exists();

            if ($alreadyOpen) {
                continue;
            }

            $this->raise($website, $findings);
            $flagged++;
        }

        $this->info("{$flagged} situs menemukan kode asing di halaman depan.");

        return self::SUCCESS;
    }

    /**
     * @param  array<int, array{jenis: string, detail: string}>  $findings
     */
    protected function raise(Website $website, array $findings): void
    {
        $summary = collect($findings)
            ->map(fn (array $finding) => "{$finding['jenis']}: {$finding['detail']}")
            ->take(5)
            ->implode('; ');

        $incident = Incident::create([
            'website_id' => $website->id,
            'type' => 'attack',
            // Not critical: a hidden iframe is sometimes a legitimate widget, and
            // the site is still serving. It needs a human to look, not a siren.
            'severity' => 'warning',
            'title' => "Kode asing terdeteksi di {$website->name}",
            'description' => 'Halaman depan memuat kode yang tidak dikenali: '.$summary,
            'meta' => ['reason' => 'foreign_script', 'temuan' => $findings],
            'status' => 'open',
            'detected_at' => now(),
        ]);

        $admins = User::all();
        $notification = new AttackAnomalyDetected($website, $incident);

        app(MailSettings::class)->applyToRuntime();
        Notification::send($admins, $notification);

        app(WebhookNotifier::class)->sendNotification($notification, $admins->first());
    }
}
