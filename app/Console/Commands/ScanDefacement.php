<?php

namespace App\Console\Commands;

use App\Models\Website;
use App\Services\Monitoring\DefacementMonitor;
use App\Services\Monitoring\DefacementScanner;
use App\Services\Monitoring\PageContentMonitor;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * Daily sweep for code on a homepage that nobody at the institution put there,
 * plus the homepage's content-defacement check (see DefacementMonitor).
 *
 * Reuses the existing 'attack' incident type with its own reason rather than
 * inventing a parallel structure - it lands on the Insiden page, in the email
 * template, and in the chat channel with no new plumbing at all.
 */
class ScanDefacement extends Command
{
    protected $signature = 'websites:scan-defacement
        {--site= : Scan only the website with this uuid}';

    protected $description = 'Scan homepages for foreign scripts, hidden frames, obfuscated code, and content defacement';

    public function handle(DefacementScanner $scanner, DefacementMonitor $monitor, PageContentMonitor $content): int
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
                $scanner->scan($website);
                $homepage = $scanner->lastHomepage();

                // A failed fetch says nothing about injection or content - leave both as they were.
                if ($homepage === null) {
                    continue;
                }

                // Injection is judged per page against its risk baseline inside observe() (ADR 0002).
                $url = Str::before($website->url, '#');
                $content->observe($website, $url, $homepage['text'], $homepage['last_modified'], response: [
                    'body' => $homepage['body'],
                    'status' => $homepage['status'] ?? null,
                    'final_url' => $homepage['final_url'] ?? $url,
                    'redirects' => $homepage['redirects'] ?? 0,
                ]);
                $monitor->settle($website, [sha1($url)]);

                if ($website->defacementFindings()->open()->where('kind', 'injection')->where('url_hash', sha1($url))->exists()) {
                    $flagged++;
                }
            } catch (Throwable $e) {
                $this->warn("{$website->name}: {$e->getMessage()}");
            }
        }

        $this->info("{$flagged} situs menemukan kode asing di halaman depan.");

        return self::SUCCESS;
    }
}
