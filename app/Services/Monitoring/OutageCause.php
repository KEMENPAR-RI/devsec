<?php

namespace App\Services\Monitoring;

use App\Models\Incident;
use App\Models\Website;
use App\Models\WebsiteCheck;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Reads the shape of the checks leading up to an outage and names the most likely
 * cause - always as a suspicion with the evidence attached, never as a verdict.
 *
 * The distinction matters more here than anywhere else in this app. From outside
 * a server, a DDoS and a full disk and a dead MySQL and an unusually popular
 * announcement all produce the same thing: a request that does not come back.
 * Proving an attack needs the access log and the network graphs, which this app
 * cannot see. What it CAN see is the run-up - and the run-up has a different
 * shape per cause:
 *
 *   - overload (a DDoS among other things) degrades first. Response times climb
 *     over consecutive checks and only then time out.
 *   - an application or database fault answers fast and wrong: a 5xx returned
 *     promptly, with no slow-down before it.
 *   - a dead host or bad DNS refuses instantly, with no status code at all.
 *
 * So this reports the pattern and what it is consistent with, and says out loud
 * that the server's own logs are what settles it. An operator told "DDoS" when
 * the truth was a crashed database calls their provider about the wrong thing
 * while the real fault stays untouched - which is worse than saying nothing.
 */
class OutageCause
{
    /** Checks before the failure examined for a run-up pattern. */
    protected const RUNUP_CHECKS = 5;

    /** A climb of this many times the earliest sample reads as degradation. */
    protected const SLOWDOWN_FACTOR = 2.0;

    /** How far back an attack incident still counts as related to this outage. */
    protected const CORRELATION_MINUTES = 30;

    /**
     * What the run-up to this failed check suggests, if anything.
     *
     * Null when the evidence supports nothing in particular - an honest silence
     * rather than a guessed label.
     *
     * @return array{cause: string, evidence: string, caveat: string, is_overload: bool}|null
     */
    public function diagnose(Website $website, WebsiteCheck $failed): ?array
    {
        // A fast, definite refusal: nothing was listening. Overload does not look
        // like this - it takes time to fail.
        if ($this->isImmediateRefusal($failed)) {
            return [
                'cause' => 'Server tidak merespons sama sekali',
                'evidence' => 'Koneksi ditolak seketika ('.$failed->response_time_ms.'ms) tanpa kode status HTTP'
                    .($failed->error_message ? ': '.$failed->error_message : '').'.',
                'caveat' => 'Bisa berarti layanan web mati, server padam, atau masalah DNS. Perlu dicek dari sisi server.',
                'is_overload' => false,
            ];
        }

        // 429/503 are what a rate limiter or an overwhelmed upstream returns -
        // often the site's own protection holding a flood back.
        if (in_array($failed->status_code, [429, 503], true)) {
            return [
                'cause' => 'Server menolak permintaan karena kelebihan beban',
                'evidence' => "Server membalas HTTP {$failed->status_code}, kode yang dikeluarkan saat permintaan dibatasi atau server kewalahan.",
                'caveat' => 'Konsisten dengan lonjakan trafik atau serangan DDoS, tapi juga muncul saat proteksi rate-limit aktif. Perlu konfirmasi dari access log server.',
                'is_overload' => true,
            ];
        }

        // Answered quickly, just wrongly. A flood does not produce a prompt 500.
        if ($this->isApplicationError($failed)) {
            return [
                'cause' => 'Kesalahan aplikasi atau database',
                'evidence' => "Server membalas HTTP {$failed->status_code} dengan cepat ({$failed->response_time_ms}ms), bukan melambat lebih dulu.",
                'caveat' => 'Pola ini tidak menunjukkan kelebihan beban. Periksa log error aplikasi dan status database.',
                'is_overload' => false,
            ];
        }

        // Nothing conclusive from the failure itself - look at how it got there.
        return $this->diagnoseFromRunup($website, $failed);
    }

    /**
     * The overload case, which only the run-up can show: a site that got slower
     * and slower before it stopped answering.
     *
     * @return array{cause: string, evidence: string, caveat: string, is_overload: bool}|null
     */
    protected function diagnoseFromRunup(Website $website, WebsiteCheck $failed): ?array
    {
        $runup = $this->recentSuccessfulChecks($website, $failed);

        if ($runup->count() < 2) {
            return null;
        }

        $earliest = (float) $runup->first()->response_time_ms;
        $latest = (float) $runup->last()->response_time_ms;

        // A site that is normally instant can double on noise alone; require a
        // baseline with enough room for the ratio to mean something.
        if ($earliest < 50.0 || $latest < $earliest * self::SLOWDOWN_FACTOR) {
            return null;
        }

        $trail = $runup->map(fn (WebsiteCheck $check) => number_format($check->response_time_ms).'ms')->implode(' > ');
        $spike = $this->recentAttackIncident($website, $failed);

        $evidence = "Response time naik bertahap sebelum gagal: {$trail}, lalu tidak merespons.";

        if ($spike) {
            // The strongest signal available without server access: the anomaly
            // detector had already flagged this site before it went down.
            $minutes = (int) round($spike->detected_at->diffInMinutes($failed->checked_at));
            $evidence .= " Insiden lonjakan response time sudah tercatat {$minutes} menit sebelumnya.";
        }

        return [
            'cause' => 'Kelebihan beban / kemungkinan serangan DDoS',
            'evidence' => $evidence,
            'caveat' => 'Pola ini juga muncul saat trafik melonjak wajar atau sumber daya server habis. Butuh access log server untuk memastikan serangan.',
            'is_overload' => true,
        ];
    }

    /**
     * Successful checks immediately before the failure, oldest first.
     *
     * @return Collection<int, WebsiteCheck>
     */
    protected function recentSuccessfulChecks(Website $website, WebsiteCheck $failed): Collection
    {
        return $website->checks()
            ->where('is_up', true)
            ->where('checked_at', '<=', $failed->checked_at)
            ->where('id', '!=', $failed->id)
            ->whereNotNull('response_time_ms')
            ->latest('checked_at')
            ->limit(self::RUNUP_CHECKS)
            ->get()
            ->reverse()
            ->values();
    }

    /**
     * An open response-time-spike incident raised shortly before this outage, if
     * there is one.
     */
    protected function recentAttackIncident(Website $website, WebsiteCheck $failed): ?Incident
    {
        return $website->incidents()
            ->where('type', 'attack')
            ->whereJsonContains('meta->reason', 'response_time_spike')
            ->where('detected_at', '<=', $failed->checked_at)
            ->where('detected_at', '>=', $failed->checked_at->copy()->subMinutes(self::CORRELATION_MINUTES))
            ->latest('detected_at')
            ->first();
    }

    /**
     * No HTTP conversation happened at all, and it failed fast enough to mean the
     * host actively refused rather than went quiet under load.
     *
     * A timeout is excluded explicitly instead of being left to the duration alone.
     * "Timed out" is the one error whose message says more than its timing does: a
     * saturated server can drop a connection quickly, and a client that gives up
     * early still records a short elapsed time - both of which would otherwise be
     * read here as "the host refused" and stop the run-up from ever being examined.
     */
    protected function isImmediateRefusal(WebsiteCheck $check): bool
    {
        if ($check->status_code !== null || $check->response_time_ms >= 1000) {
            return false;
        }

        return ! Str::contains(Str::lower($check->error_message ?? ''), ['timed out', 'timeout']);
    }

    /** A server error returned promptly, rather than after a slow-down. */
    protected function isApplicationError(WebsiteCheck $check): bool
    {
        return $check->status_code !== null
            && $check->status_code >= 500
            && $check->response_time_ms < 3000;
    }
}
