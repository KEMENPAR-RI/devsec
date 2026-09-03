<?php

namespace App\Notifications;

use App\Models\JudolDetection;
use App\Models\Website;
use App\Notifications\Concerns\SendsIncidentEmail;
use Carbon\CarbonInterface;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * One notification for everything a single scan found on one site - not one per
 * flagged page. A crawl that turns up four injected pages used to mean four
 * database rows, four dropdown entries and four near-identical emails; batching
 * it here fixes all three at once, since every channel reads the same payload.
 */
class JudolContentDetected extends Notification
{
    use SendsIncidentEmail;

    /** How many URLs to spell out before falling back to "dan N lainnya". */
    protected const LISTED_URLS = 5;

    /**
     * @param  Collection<int, JudolDetection>  $detections  every page flagged in this scan
     */
    public function __construct(
        public Website $website,
        public Collection $detections,
    ) {}

    public function toArray(object $notifiable): array
    {
        $count = $this->detections->count();
        $keywords = $this->detections
            ->flatMap(fn (JudolDetection $detection) => $detection->matched_keywords ?? [])
            ->unique()
            ->take(6);

        // A page flagged purely for the gambling sites it links to has no keywords
        // to report, so the evidence line would read "Kata kunci: ." - name the
        // domains instead, which is also the more actionable thing to go look for.
        $domains = $this->detections
            ->flatMap(fn (JudolDetection $detection) => $detection->linked_domains ?? [])
            ->unique()
            ->take(6);

        $evidence = collect([
            $keywords->isNotEmpty() ? 'Kata kunci: '.$keywords->implode(', ') : null,
            $domains->isNotEmpty() ? 'Tautan ke situs judi: '.$domains->implode(', ') : null,
        ])->filter()->implode('. ');

        $urls = $this->detections->pluck('url');
        $earliest = $this->detections->min('detected_at') ?? now();

        return [
            'type' => 'judol',
            'severity' => 'critical',
            'title' => $count > 1
                ? "{$count} halaman judol baru di {$this->website->name}"
                : "Konten judi online di {$this->website->name}",
            'message' => $count > 1
                ? "{$count} halaman terindikasi konten judi online. {$evidence}."
                : $evidence.'.',
            'website_id' => $this->website->id,
            'incident_id' => $this->detections->first()?->incident_id,
            'count' => $count,
            // Listed in the email body and available to the in-app UI; capped so a
            // 60-page crawl doesn't write a novel into the notifications table.
            'items' => $urls->take(self::LISTED_URLS)->all(),
            'items_remaining' => max(0, $count - self::LISTED_URLS),
            'detected_at' => $earliest instanceof CarbonInterface
                ? $earliest->translatedFormat('d M Y, H:i')
                : (string) $earliest,
            'url' => route('deteksi-judol'),
        ];
    }
}
