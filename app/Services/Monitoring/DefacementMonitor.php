<?php

namespace App\Services\Monitoring;

use App\Models\DefacementFinding;
use App\Models\Incident;
use App\Models\PageSnapshot;
use App\Models\User;
use App\Models\Website;
use App\Notifications\AttackAnomalyDetected;
use App\Services\Notifications\MailSettings;
use App\Services\Notifications\WebhookNotifier;
use App\Support\ContentFingerprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Persists defacement verdicts: findings (one row per page+kind), baselines,
 * and the single open incident per site.
 */
class DefacementMonitor
{
    protected const INJECTION_CODES = [
        'Skrip dari domain asing' => 'foreign_script',
        'Iframe tersembunyi' => 'hidden_frame',
        'Pola kode mencurigakan' => 'obfuscated',
    ];

    protected ?DefacementDetector $detector = null;

    /**
     * Judges a page against its baseline: a defaced page freezes the baseline and
     * opens/updates a finding; a clean one recovers, and moves the baseline only
     * when it changed little (no gradual replacement through "clean" crawls).
     *
     * $snapshot must already hold the current page and its baseline columns
     * (baseline_fingerprint included).
     */
    public function evaluate(Website $website, PageSnapshot $snapshot, PageText $text, string $body, string $fingerprint): ?DefacementFinding
    {
        $settings = app(DefacementSettings::class);
        $verdict = $this->detector()->evaluate($snapshot, $text, $fingerprint, $settings);

        if ($verdict['defaced']) {
            return $this->recordContent($website, $snapshot, $verdict, $text);
        }

        // A page that could not be fully read neither moves the baseline nor clears a finding.
        if (! $text->complete) {
            return null;
        }

        // A large change keeps the old baseline: the next crawls are still judged against it.
        $smallChange = ! $verdict['eligible'] || $verdict['ratio'] === null
            || $settings->replacePercent() / 2 >= $verdict['ratio'] * 100;

        if ($smallChange) {
            $snapshot->update($this->baselineAttributes($snapshot, $body, $fingerprint, $verdict['signatures']));
        }

        $this->markRecovered($website, $snapshot->url_hash);

        return null;
    }

    /**
     * @param  array{reasons: array<int, array{code: string, detail: string}>, ratio: ?float}  $verdict
     */
    public function recordContent(Website $website, PageSnapshot $snapshot, array $verdict, PageText $text): ?DefacementFinding
    {
        return $this->upsertFinding($website, $snapshot->url_hash, 'content', [
            'page_snapshot_id' => $snapshot->id,
            'url' => $snapshot->url,
            'reasons' => $verdict['reasons'],
            'change_ratio' => $verdict['ratio'],
            'before_title' => $snapshot->baseline_title,
            'after_title' => Str::limit($text->title, 255, ''),
            'before_excerpt' => $snapshot->baseline_excerpt,
            'after_excerpt' => Str::limit($text->visibleText(), 1000, ''),
            'last_seen_at' => now(),
        ]);
    }

    /** Open findings for this page that are no longer defaced become "recovered". */
    public function markRecovered(Website $website, string $urlHash, string $kind = 'content'): int
    {
        return DefacementFinding::query()
            ->where('website_id', $website->id)
            ->where('url_hash', $urlHash)
            ->where('kind', $kind)
            ->open()
            ->update(['status' => 'recovered', 'resolved_at' => now()]);
    }

    /**
     * Records the daily homepage code-injection scan.
     *
     * @param  array<int, array{jenis: string, detail: string}>  $temuan
     * @return bool whether a new incident was raised
     */
    public function recordInjection(Website $website, array $temuan): bool
    {
        $url = Str::before($website->url, '#');
        $urlHash = sha1($url);

        if ($temuan === []) {
            $this->markRecovered($website, $urlHash, 'injection');
            $this->openInjectionIncident($website)?->resolve();

            return false;
        }

        $reasons = collect($temuan)
            ->map(fn (array $t) => ['code' => self::INJECTION_CODES[$t['jenis']] ?? 'foreign_script', 'detail' => "{$t['jenis']}: {$t['detail']}"])
            ->values()
            ->all();

        $existing = DefacementFinding::query()
            ->where('website_id', $website->id)
            ->where('url_hash', $urlHash)
            ->where('kind', 'injection')
            ->first();

        // Marked legitimate and still the very same code: stay quiet.
        if ($existing?->status === 'legitimate' && $this->sameReasons($existing->reasons ?? [], $reasons)) {
            return false;
        }

        $finding = $this->upsertFinding($website, $urlHash, 'injection', [
            'url' => $url,
            'reasons' => $reasons,
            'after_excerpt' => collect($reasons)->pluck('detail')->take(10)->implode("\n"),
            'last_seen_at' => now(),
        ]);

        $incident = $this->openInjectionIncident($website);
        $raised = $incident === null;
        $incident ??= $this->raiseInjection($website, $temuan);

        if ($finding && $finding->incident_id !== $incident->id) {
            $finding->update(['incident_id' => $incident->id]);
        }

        return $raised;
    }

    /**
     * One open defacement incident per site: created (and notified) for the first
     * open content finding, updated quietly after, resolved when none is left.
     *
     * @param  array<int, string>  $seenUrlHashes  pages fetched just now - their open
     *                                             findings are still there, even when unchanged
     */
    public function settle(Website $website, array $seenUrlHashes = []): void
    {
        if ($seenUrlHashes !== []) {
            foreach (array_chunk($seenUrlHashes, 500) as $chunk) {
                $website->defacementFindings()->open()->where('kind', 'content')
                    ->whereIn('url_hash', $chunk)
                    ->update(['last_seen_at' => now()]);
            }
        }

        $open = $website->defacementFindings()
            ->open()
            ->where('kind', 'content')
            ->latest('last_seen_at')
            ->get(['id', 'uuid', 'url', 'reasons', 'before_title', 'after_title', 'before_excerpt', 'after_excerpt', 'incident_id']);

        $incident = $website->incidents()
            ->open()
            ->where('type', 'attack')
            ->whereJsonContains('meta->reason', 'defacement')
            ->first();

        if ($open->isEmpty()) {
            $incident?->resolve();

            return;
        }

        $first = $open->first();
        $reasons = $open->flatMap(fn (DefacementFinding $f) => collect($f->reasons ?? [])->pluck('detail'))->unique()->take(10)->values()->all();
        $urls = $open->pluck('url')->unique()->take(10)->values()->all();

        $attributes = [
            'description' => 'Konten halaman '.implode(', ', array_slice($urls, 0, 3)).($open->count() > 3 ? ' dan '.($open->count() - 3).' halaman lain' : '')
                .' berubah menyerupai defacement: '.implode('; ', array_slice($reasons, 0, 3)),
            'meta' => [
                'reason' => 'defacement',
                'urls' => $urls,
                'reasons' => $reasons,
                'before' => Str::limit(trim(($first->before_title ? $first->before_title.' - ' : '').$first->before_excerpt), 300),
                'after' => Str::limit(trim(($first->after_title ? $first->after_title.' - ' : '').$first->after_excerpt), 300),
                'finding_uuids' => $open->pluck('uuid')->take(20)->values()->all(),
            ],
        ];

        if ($incident) {
            $incident->update($attributes);
        } else {
            $incident = Incident::create($attributes + [
                'website_id' => $website->id,
                'type' => 'attack',
                'severity' => 'critical',
                'title' => "Defacement terdeteksi di {$website->name}",
                'status' => 'open',
                'detected_at' => now(),
            ]);

            $this->notify($website, $incident);
        }

        DefacementFinding::query()
            ->whereIn('id', $open->where('incident_id', '!=', $incident->id)->pluck('id'))
            ->update(['incident_id' => $incident->id]);
    }

    /**
     * The current page becomes the new baseline and the finding stops counting.
     * Refused (false) unless the finding is open and, for content, the page is still
     * the one the operator saw ($contentHash).
     */
    public function markLegitimate(DefacementFinding $finding, User $user, ?string $contentHash = null): bool
    {
        $marked = DB::transaction(function () use ($finding, $user, $contentHash) {
            $finding = DefacementFinding::query()->lockForUpdate()->find($finding->id);

            if (! $finding || $finding->status !== 'open') {
                return false;
            }

            $snapshot = $finding->kind === 'content' ? $finding->snapshot()->lockForUpdate()->first() : null;

            if ($snapshot && ($contentHash === null || ! hash_equals((string) $snapshot->content_hash, $contentHash))) {
                return false;
            }

            if ($snapshot) {
                $body = (string) $snapshot->text();
                $snapshot->update($this->baselineAttributes(
                    $snapshot,
                    $body,
                    ContentFingerprint::fingerprint($body),
                    $this->detector()->signatures($snapshot->title."\n".$body),
                ));
            }

            $finding->update(['status' => 'legitimate', 'resolved_by' => $user->id, 'resolved_at' => now()]);

            return true;
        });

        if ($marked) {
            $finding->refresh();
            $this->afterClosing($finding);
        }

        return $marked;
    }

    public function markResolved(DefacementFinding $finding, User $user): void
    {
        $finding->update(['status' => 'resolved', 'resolved_by' => $user->id, 'resolved_at' => now()]);

        $this->afterClosing($finding);
    }

    /** Settles incidents after a finding was closed or deleted by hand. */
    public function afterClosing(DefacementFinding $finding): void
    {
        $website = $finding->website;

        if (! $website) {
            return;
        }

        if ($finding->kind === 'injection') {
            $stillOpen = $website->defacementFindings()->open()->where('kind', 'injection')->exists();

            if (! $stillOpen) {
                $this->openInjectionIncident($website)?->resolve();
            }

            return;
        }

        $this->settle($website);
    }

    /**
     * @param  array<int, string>  $signatures
     * @return array<string, mixed>
     */
    protected function baselineAttributes(PageSnapshot $snapshot, string $body, string $fingerprint, array $signatures): array
    {
        return [
            'baseline_hash' => $snapshot->content_hash,
            'baseline_fingerprint' => $fingerprint,
            'baseline_title' => $snapshot->title,
            'baseline_excerpt' => Str::limit($body, 1000, ''),
            'baseline_text_length' => $snapshot->text_length,
            'baseline_signatures' => array_values($signatures),
            'baseline_at' => now(),
        ];
    }

    /**
     * Updates the page's finding of this kind, reopening a closed one; retries once
     * on a unique-key race with the other scanner.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function upsertFinding(Website $website, string $urlHash, string $kind, array $attributes): ?DefacementFinding
    {
        $key = ['website_id' => $website->id, 'url_hash' => $urlHash, 'kind' => $kind];

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $finding = DefacementFinding::query()->firstOrNew($key);

            if (! $finding->exists || $finding->status !== 'open') {
                $finding->fill(['status' => 'open', 'first_seen_at' => now(), 'resolved_at' => null, 'resolved_by' => null, 'incident_id' => null]);
            }

            try {
                $finding->fill($attributes)->save();

                return $finding;
            } catch (UniqueConstraintViolationException) {
                continue;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array{code: string, detail: string}>  $a
     * @param  array<int, array{code: string, detail: string}>  $b
     */
    protected function sameReasons(array $a, array $b): bool
    {
        $details = fn (array $reasons) => collect($reasons)->pluck('detail')->sort()->values()->all();

        return $details($a) === $details($b);
    }

    protected function openInjectionIncident(Website $website): ?Incident
    {
        return $website->incidents()
            ->open()
            ->where('type', 'attack')
            ->whereJsonContains('meta->reason', 'foreign_script')
            ->first();
    }

    /**
     * @param  array<int, array{jenis: string, detail: string}>  $temuan
     */
    protected function raiseInjection(Website $website, array $temuan): Incident
    {
        $summary = collect($temuan)
            ->map(fn (array $finding) => "{$finding['jenis']}: {$finding['detail']}")
            ->take(5)
            ->implode('; ');

        $incident = Incident::create([
            'website_id' => $website->id,
            'type' => 'attack',
            // Not critical: a hidden iframe is sometimes a legitimate widget.
            'severity' => 'warning',
            'title' => "Kode asing terdeteksi di {$website->name}",
            'description' => 'Halaman depan memuat kode yang tidak dikenali: '.$summary,
            'meta' => ['reason' => 'foreign_script', 'temuan' => $temuan],
            'status' => 'open',
            'detected_at' => now(),
        ]);

        $this->notify($website, $incident);

        return $incident;
    }

    protected function notify(Website $website, Incident $incident): void
    {
        $admins = User::all();
        $notification = new AttackAnomalyDetected($website, $incident);

        app(MailSettings::class)->applyToRuntime();
        Notification::send($admins, $notification);

        app(WebhookNotifier::class)->sendNotification($notification, $admins->first());
    }

    public function detector(): DefacementDetector
    {
        return $this->detector ??= app(DefacementDetector::class);
    }
}
