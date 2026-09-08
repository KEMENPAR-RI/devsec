<?php

namespace App\Console\Commands;

use App\Models\Incident;
use App\Models\Website;
use App\Services\Monitoring\OutageCause;
use Illuminate\Console\Command;

/**
 * Fabricates the check history behind an outage so its diagnosis and status dot
 * can be seen on a real dashboard without waiting for a real site to fail.
 *
 * This writes the SAME rows a real outage writes and then runs the real
 * OutageCause over them, so what appears on screen is the actual diagnosis, not a
 * hard-coded label. Only the checks themselves are invented.
 *
 * Development aid, not a monitoring feature: it refuses to run outside a local
 * environment, since inventing check history on a production dashboard would make
 * an operator investigate an outage that never happened.
 */
class SimulateOutage extends Command
{
    protected $signature = 'websites:simulate-outage
        {website? : Name or URL fragment of the site to use (default: the first one)}
        {--pattern=ddos : ddos, apperror, refused, or plain}
        {--clear : Remove simulated checks and incidents from the site instead}';

    protected $description = 'Fabricate an outage on a website to preview its diagnosis and status dot (local only)';

    /**
     * The check history each pattern produces, as [response_time_ms, status_code,
     * error] for the run-up plus the failure that follows it.
     */
    protected const PATTERNS = [
        'ddos' => [
            'label' => 'Kelebihan beban / kemungkinan DDoS',
            'runup' => [320, 1500, 6800],
            'fail' => [20000, null, 'Connection timed out'],
            'spike' => true,
        ],
        'apperror' => [
            'label' => 'Kesalahan aplikasi / database',
            'runup' => [300, 310, 295],
            'fail' => [180, 500, 'HTTP 500'],
            'spike' => false,
        ],
        'refused' => [
            'label' => 'Server tidak merespons',
            'runup' => [300, 320],
            'fail' => [40, null, 'Could not resolve host'],
            'spike' => false,
        ],
        'plain' => [
            'label' => 'Down tanpa pola yang bisa disimpulkan',
            'runup' => [300, 310, 305],
            'fail' => [20000, null, 'Connection timed out'],
            'spike' => false,
        ],
    ];

    public function handle(): int
    {
        if (! app()->environment('local', 'testing')) {
            $this->error('Perintah ini hanya untuk environment local - menulis riwayat cek palsu di produksi akan membuat operator menyelidiki insiden yang tidak pernah terjadi.');

            return self::FAILURE;
        }

        $website = $this->resolveWebsite();

        if (! $website) {
            $this->error('Situs tidak ditemukan. Tambahkan satu lewat dashboard dulu.');

            return self::FAILURE;
        }

        if ($this->option('clear')) {
            return $this->clear($website);
        }

        $pattern = $this->option('pattern');

        if (! isset(self::PATTERNS[$pattern])) {
            $this->error("Pattern '{$pattern}' tidak dikenal. Pilih: ".implode(', ', array_keys(self::PATTERNS)));

            return self::FAILURE;
        }

        return $this->simulate($website, $pattern);
    }

    protected function simulate(Website $website, string $pattern): int
    {
        $recipe = self::PATTERNS[$pattern];
        $this->info("Menyimulasikan '{$recipe['label']}' pada {$website->name}...");

        // Start from a clean slate so a previous run's history cannot bleed into
        // the diagnosis of this one.
        $this->wipe($website);

        $at = now()->subMinutes(count($recipe['runup']) * 2 + 2);

        foreach ($recipe['runup'] as $ms) {
            $website->checks()->create([
                'is_up' => true,
                'status_code' => 200,
                'response_time_ms' => $ms,
                'content_length' => 50000,
                'checked_at' => $at,
            ]);
            $at = $at->copy()->addMinutes(2);
        }

        // The spike incident WebsiteChecker would have opened on the way down, so
        // the diagnosis can correlate against it exactly as it would in production.
        if ($recipe['spike']) {
            $baseline = $recipe['runup'][0];
            $peak = end($recipe['runup']);

            Incident::create([
                'website_id' => $website->id,
                'type' => 'attack',
                'severity' => 'warning',
                'title' => "Lonjakan response time pada {$website->name}",
                'description' => 'Disimulasikan oleh websites:simulate-outage.',
                'meta' => ['reason' => 'response_time_spike', 'response_time_ms' => $peak, 'baseline_ms' => $baseline],
                'status' => 'open',
                'detected_at' => $at->copy()->subMinutes(4),
            ]);
        }

        [$failMs, $failStatus, $failError] = $recipe['fail'];

        $failed = $website->checks()->create([
            'is_up' => false,
            'status_code' => $failStatus,
            'response_time_ms' => $failMs,
            'error_message' => $failError,
            'checked_at' => $at,
        ]);

        // The real diagnoser over the fabricated history - what shows on screen is
        // whatever it actually concludes.
        $diagnosis = app(OutageCause::class)->diagnose($website, $failed);

        Incident::create([
            'website_id' => $website->id,
            'type' => 'downtime',
            'severity' => 'critical',
            'title' => "{$website->name} tidak dapat diakses",
            'description' => $failError ?? 'Website gagal merespons.',
            'meta' => [
                'consecutive_failures' => $website->down_threshold_failures,
                'status_code' => $failStatus,
                'error_message' => $failError,
                ...($diagnosis ? [
                    'likely_cause' => $diagnosis['cause'],
                    'cause_evidence' => $diagnosis['evidence'],
                    'cause_caveat' => $diagnosis['caveat'],
                    'cause_is_overload' => $diagnosis['is_overload'],
                ] : []),
            ],
            'status' => 'open',
            'detected_at' => $at,
        ]);

        $website->update([
            'last_status' => 'down',
            'consecutive_failures' => $website->down_threshold_failures,
            'last_checked_at' => $at,
        ]);

        $this->newLine();
        $this->line('  Diagnosa   : '.($diagnosis['cause'] ?? '(tidak ada - bukti tidak cukup)'));

        if ($diagnosis) {
            $this->line('  Bukti      : '.$diagnosis['evidence']);
        }

        $this->line('  Status dot : '.$website->fresh()->statusKey(false, null, $website->fresh()->wasAttacked()));
        $this->newLine();
        $this->info("Buka halaman situs '{$website->name}' di dashboard untuk melihatnya.");
        $this->comment('Bersihkan dengan: php artisan websites:simulate-outage --clear');

        return self::SUCCESS;
    }

    protected function clear(Website $website): int
    {
        $this->wipe($website);

        $website->update(['last_status' => 'up', 'consecutive_failures' => 0]);

        $this->info("Riwayat simulasi pada {$website->name} dihapus, status dikembalikan ke up.");

        return self::SUCCESS;
    }

    /** Removes the checks and open incidents a previous simulation left behind. */
    protected function wipe(Website $website): void
    {
        $website->checks()->delete();
        $website->incidents()->whereIn('type', ['downtime', 'attack'])->delete();
    }

    protected function resolveWebsite(): ?Website
    {
        $needle = $this->argument('website');

        return $needle
            ? Website::where('name', 'like', "%{$needle}%")->orWhere('url', 'like', "%{$needle}%")->first()
            : Website::first();
    }
}
