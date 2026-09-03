<?php

namespace App\Services\Monitoring;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Judges the domains a page links OUT to, catching the one compromise the keyword
 * filter cannot see.
 *
 * JudolAiClassifier answers "is this page's content gambling spam?". This answers
 * a different question: "does this page point at a gambling site?" - the signature
 * of backlink injection, where an attacker leaves the visible article untouched
 * and only adds hidden <a href="https://some-brand.com"> tags to pass SEO
 * authority to their own site. The page reads perfectly clean, no keyword ever
 * matches, and today it scans as healthy.
 *
 * A keyword list cannot cover this either: gambling brands are invented weekly
 * and named to look like anything ("dewa89", "olxtoto", "garuda4d", "mpo808").
 * Recognising them is exactly what a model is good at.
 *
 * Each link is judged on its domain AND the anchor text it was placed under,
 * because half the time only one of the two gives the game away: a real page in
 * this app's site list linked to "wicc2024.com" - which reads like a conference -
 * under the words "Live Draw Macau".
 *
 * Cost is kept near zero by caching the verdict PER LINK, not per page: a footer
 * link repeated across sixty crawled pages is judged once, and on later scans the
 * whole site usually resolves from cache without any API call at all.
 */
class JudolLinkClassifier
{
    /** How long a domain's verdict is trusted before it is asked about again. */
    protected const CACHE_DAYS = 30;

    /** Domains asked about in one call - enough for a page's whole link list. */
    protected const MAX_DOMAINS_PER_CALL = 40;

    public function __construct(protected JudolAiSettings $settings) {}

    /**
     * Which of these links point at online-gambling sites.
     *
     * @param  array<string, string>  $links  registrable domain => the anchor text
     *                                        it was linked under on this page
     * @return array<int, string> the gambling domains, in the order they were given.
     *                            An unreachable AI yields an empty list: this
     *                            classifier only ever ADDS a finding, so failing
     *                            quiet loses a detection rather than inventing one.
     */
    public function gamblingDomains(array $links): array
    {
        if ($links === []) {
            return [];
        }

        $flagged = [];
        $unknown = [];

        foreach ($links as $domain => $anchor) {
            $cached = Cache::get($this->cacheKey($domain, $anchor));

            match ($cached) {
                true => $flagged[] = $domain,
                false => null,
                default => $unknown[$domain] = $anchor,
            };
        }

        foreach (array_chunk($unknown, self::MAX_DOMAINS_PER_CALL, preserve_keys: true) as $chunk) {
            $verdicts = $this->ask($chunk);

            // A failed call returns null rather than an empty verdict list, so a
            // timeout is not cached as "all of these are clean" for a month.
            if ($verdicts === null) {
                continue;
            }

            foreach (array_keys($chunk) as $position => $domain) {
                $isGambling = ($verdicts[$position + 1] ?? false) === true;

                Cache::put($this->cacheKey($domain, $chunk[$domain]), $isGambling, now()->addDays(self::CACHE_DAYS));

                if ($isGambling) {
                    $flagged[] = $domain;
                }
            }
        }

        // Back into the caller's order, so the stored list reads the way the page does.
        return array_values(array_filter(array_keys($links), fn (string $domain) => in_array($domain, $flagged, true)));
    }

    /**
     * A short sentence naming what the flagged domains are, for the detection's
     * AI reasoning field. Written here so the wording lives next to the check that
     * produced it.
     *
     * @param  array<int, string>  $domains
     */
    public function summarize(array $domains): string
    {
        $count = count($domains);

        return "Halaman ini memasang {$count} tautan keluar ke situs judi online yang dikenali AI: "
            .implode(', ', $domains).'. Pola ini khas penyisipan backlink pada situs yang diretas - '
            .'isi halaman dibiarkan normal supaya tidak mencurigakan, dan hanya tautannya yang ditanam.';
    }

    /**
     * One call, one verdict per link.
     *
     * Verdicts come back keyed by the link's position in the list, not by its
     * domain: a JSON object cannot hold the same domain twice, and domain strings
     * are the model's to retype - and therefore to typo. A number it only has to
     * copy back.
     *
     * @param  array<string, string>  $links  domain => anchor text
     * @return array<int, bool>|null null when the AI could not be reached or
     *                               answered with something unreadable - never an
     *                               empty array, which would be cached as a clean
     *                               bill of health for every link asked about.
     */
    protected function ask(array $links): ?array
    {
        $listing = [];
        $position = 0;

        foreach ($links as $domain => $anchor) {
            $position++;
            $listing[] = $anchor !== ''
                ? "{$position}. {$domain} - teks tautan: \"{$anchor}\""
                : "{$position}. {$domain} - teks tautan: (kosong)";
        }

        try {
            $message = $this->settings->client()->messages->create(
                model: $this->settings->model(),
                maxTokens: 1000,
                system: <<<'SYS'
                Kamu menilai tautan keluar dari sebuah halaman web. Untuk setiap
                tautan, tentukan apakah tautan itu mengarah ke situs JUDI ONLINE
                (slot, togel/toto, casino, taruhan bola, poker).

                Nilai DUA hal sekaligus - nama domain DAN teks tautannya. Salah satu
                saja sudah cukup untuk menjawab true:
                - Nama merek judi biasanya menggabungkan kata seperti slot, toto,
                  togel, bet, win, cuan, gacor, hoki, jp, wd dengan angka (88, 77,
                  4d, 89) - mis. "dewa89", "olxtoto", "garuda4d", "mpo808".
                - Teks tautannya sendiri sering menjadi satu-satunya petunjuk, karena
                  domainnya sengaja dibuat terdengar netral. Teks seperti "Live Draw
                  Macau", "Slot Gacor", "Link Alternatif", "RTP Hari Ini", "Bandar
                  Togel" adalah ajakan judi, apa pun nama domainnya.

                Yang BUKAN judi: situs berita, pemerintah (.go.id), kampus (.ac.id),
                media sosial, CDN, dan layanan umum - meski namanya mengandung angka,
                dan meski tautannya banyak.

                Kalau ragu, jawab false. Lebih baik melewatkan satu tautan daripada
                menuduh situs yang sah.

                Balas HANYA JSON valid, tanpa teks lain, memetakan NOMOR setiap
                tautan ke true/false, mis:
                {"1": true, "2": false, "3": false}
                SYS,
                messages: [['role' => 'user', 'content' => "Tautan:\n".implode("\n", $listing)]],
            );
        } catch (Throwable $e) {
            Log::warning('JudolLinkClassifier: gagal menilai domain: '.$e->getMessage());

            return null;
        }

        foreach ($message->content as $block) {
            if ($block->type === 'text' && $decoded = $this->decodeVerdicts($block->text)) {
                return $decoded;
            }
        }

        Log::warning('JudolLinkClassifier: respons AI tidak bisa di-parse.');

        return null;
    }

    /**
     * Pulls the domain->bool object out of the reply, tolerating the code fence and
     * the stray sentence models add despite being told not to - the same leniency
     * JudolAiClassifier needs for the same reason.
     *
     * @return array<int, bool>|null
     */
    protected function decodeVerdicts(string $text): ?array
    {
        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        if ($start === false || $end === false || $end < $start) {
            return null;
        }

        $decoded = json_decode(substr($text, $start, $end - $start + 1), true);

        if (! is_array($decoded) || $decoded === []) {
            return null;
        }

        $verdicts = [];

        foreach ($decoded as $position => $isGambling) {
            $verdicts[(int) $position] = $isGambling === true;
        }

        return $verdicts;
    }

    /**
     * Keyed by the pair, not the domain alone: the same host judged clean under
     * "Tentang Kami" has to be asked about again when it turns up under "Live Draw
     * Macau", because the anchor text is half of what the verdict was based on.
     */
    protected function cacheKey(string $domain, string $anchor): string
    {
        return 'judol:link:'.sha1($domain.'|'.Str::lower($anchor));
    }
}
