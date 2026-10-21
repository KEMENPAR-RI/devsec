<?php

namespace App\Services\Monitoring;

use App\Models\PageSnapshot;
use App\Support\AhoCorasick;
use App\Support\ContentFingerprint;

/**
 * Pure defacement verdict for one page against its baseline - no DB writes.
 *
 * (a) a defacer signature that was not already in the baseline, (b) title and
 * most of the content replaced, (c) the page shrunk to almost nothing.
 */
class DefacementDetector
{
    /** Weak signatures need this much replaced content when the title is unchanged. */
    protected const WEAK_SIGNATURE_RATIO = 0.30;

    protected const TITLE_SIMILARITY = 0.5;

    protected ?AhoCorasick $automaton = null;

    /**
     * @return array{defaced: bool, reasons: array<int, array{code: string, detail: string}>, ratio: ?float, signatures: array<int, string>, eligible: bool}
     */
    public function evaluate(PageSnapshot $baseline, PageText $current, string $currentFp, DefacementSettings $settings, ?string $riskSeverity = null): array
    {
        $found = $this->signatures($current->title."\n".$current->visibleText());
        $known = (array) ($baseline->baseline_signatures ?? []);
        $strong = (array) config('defacement.signatures.strong', []);
        $newStrong = array_values(array_diff(array_intersect($found, $strong), $known));
        $newWeak = array_values(array_diff(array_diff($found, $strong), $known));
        $reasons = [];

        if ($newStrong !== []) {
            $reasons[] = ['code' => 'signature', 'detail' => 'Signature defacer: '.implode(', ', $newStrong)];
        }

        // No baseline yet: only a strong signature can be judged.
        if ($baseline->baseline_hash === null) {
            return ['defaced' => $reasons !== [], 'reasons' => $reasons, 'ratio' => null, 'signatures' => $found, 'eligible' => false];
        }

        $ratio = ContentFingerprint::replacedRatio($baseline->baseline_fingerprint, $currentFp);
        $titleChanged = $this->titleChanged((string) $baseline->baseline_title, $current->title);

        if ($newStrong === [] && $newWeak !== [] && ($titleChanged || $ratio >= self::WEAK_SIGNATURE_RATIO)) {
            $reasons[] = ['code' => 'signature', 'detail' => 'Signature defacer: '.implode(', ', $newWeak)];
        }

        $eligible = (int) $baseline->baseline_text_length >= $settings->minBaselineChars()
            && ContentFingerprint::count($baseline->baseline_fingerprint) >= 3;

        if ($eligible) {
            // A small (few blocks AND short) maintenance/coming-soon page is neither
            // "replaced" nor "shrunk"; strong signatures above still fire.
            $placeholder = ($current->blockCount <= 3 && $current->textLength < 300)
                && TextNormalizer::isBenignPlaceholder($current->title.' '.$current->visibleText());

            $shrunk = $current->textLength <= (1 - $settings->shrinkPercent() / 100) * $baseline->baseline_text_length
                && ($current->blockCount <= 3 || $current->textLength < 300)
                && ! $placeholder;

            // Without a title change, a rotating news portal reaches 90% on its own:
            // it also needs a structural risk signal, a shrink or a weak signature.
            // A page that was not profiled (null) keeps the old rule.
            $corroborated = $riskSeverity === null || $riskSeverity !== 'none' || $shrunk || $newWeak !== [];

            $replaced = ! $placeholder && (($titleChanged && $ratio * 100 >= $settings->replacePercent())
                || ($ratio * 100 >= $settings->replaceWithoutTitlePercent() && $corroborated));

            if ($replaced) {
                $reasons[] = ['code' => 'replaced', 'detail' => sprintf('%d%% konten baseline terganti%s', round($ratio * 100), $titleChanged ? ' dan judul berubah' : '')];
            }

            if ($shrunk) {
                $reasons[] = ['code' => 'shrink', 'detail' => sprintf('Halaman menyusut dari %s menjadi %s karakter', number_format($baseline->baseline_text_length), number_format($current->textLength))];
            }
        }

        return ['defaced' => $reasons !== [], 'reasons' => $reasons, 'ratio' => $ratio, 'signatures' => $found, 'eligible' => $eligible];
    }

    /**
     * Strong signatures on the page that are not in the baseline.
     *
     * @param  array<int, string>  $known
     * @return array<int, string>
     */
    public function newStrongSignatures(PageText $current, array $known): array
    {
        $found = $this->signatures($current->title."\n".$current->visibleText());

        return array_values(array_diff(array_intersect($found, (array) config('defacement.signatures.strong', [])), $known));
    }

    /**
     * Configured signatures (strong and weak) present in the text, obfuscation folded.
     *
     * @return array<int, string>
     */
    public function signatures(string $text): array
    {
        $found = [];

        foreach ($this->automaton()->search(TextNormalizer::fold($text)) as [, $signature]) {
            $found[$signature] = true;
        }

        return array_keys($found);
    }

    protected function titleChanged(string $before, string $after): bool
    {
        $a = array_filter(explode(' ', TextNormalizer::foldKeyword($before)));
        $b = array_filter(explode(' ', TextNormalizer::foldKeyword($after)));

        if ($a === [] && $b === []) {
            return false;
        }

        $union = count(array_unique([...$a, ...$b]));
        $intersection = count(array_intersect(array_unique($a), array_unique($b)));

        return $union === 0 || $intersection / $union < self::TITLE_SIMILARITY;
    }

    protected function automaton(): AhoCorasick
    {
        if ($this->automaton) {
            return $this->automaton;
        }

        $patterns = [];

        foreach ([...(array) config('defacement.signatures.strong', []), ...(array) config('defacement.signatures.weak', [])] as $signature) {
            $folded = TextNormalizer::foldKeyword($signature);

            if ($folded !== '') {
                $patterns[' '.$folded.' '] ??= $signature;
            }
        }

        return $this->automaton = new AhoCorasick($patterns);
    }
}
