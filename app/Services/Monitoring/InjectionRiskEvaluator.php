<?php

namespace App\Services\Monitoring;

/**
 * Pure risk verdict of a page's InjectionProfile against its baseline - no DB.
 *
 * Score = weights of keys that are NEW (or absolute keys while there is no
 * baseline yet) + aggregate signals. Removed keys never add to the score.
 */
class InjectionRiskEvaluator
{
    /** Informational only: judol links raise their incident through the judol pipeline. */
    public const INFO_ONLY_CODES = ['judol_link'];

    /**
     * @param  array{items?: array<string, array<string, mixed>>, stats?: array<string, mixed>}|null  $baseline
     * @return array{score: int, severity: string, incident_severity: string, added: array<string, array<string, mixed>>, removed: array<string, array<string, mixed>>, signals: list<array<string, mixed>>, absorb: bool}
     */
    public function evaluate(?array $baseline, InjectionProfile $now): array
    {
        $config = (array) config('defacement.injection', []);
        $weights = (array) ($config['weights'] ?? []);
        $base = is_array($baseline['items'] ?? null) ? $baseline['items'] : null;
        $baseStats = (array) ($baseline['stats'] ?? []);

        if ($base === null) {
            // Stealth-hidden links (or any next to a judol link) count once they reach N domains.
            $stealthLinks = array_filter($now->items, fn (array $item, $key) => str_starts_with((string) $key, 'hlnk|') && ! empty($item['absolute']), ARRAY_FILTER_USE_BOTH);
            $hiddenAbsolute = count($stealthLinks) >= (int) ($config['hidden_links_absolute'] ?? 3);
            $added = array_filter($now->items, fn (array $item, $key) => ! empty($item['absolute'])
                && (! str_starts_with((string) $key, 'hlnk|') || $hiddenAbsolute), ARRAY_FILTER_USE_BOTH);
            $removed = [];
        } else {
            $added = array_diff_key($now->items, $base);
            $removed = array_diff_key($base, $now->items);
        }

        $signals = [];
        $hidden = [];

        foreach ($added as $key => $item) {
            if ((int) ($item['weight'] ?? 0) <= 0) {
                continue;
            }

            if (($item['code'] ?? null) === 'hidden_links') {
                $hidden[] = (string) $item['detail'];

                continue;
            }

            $signals[] = array_intersect_key($item, array_flip(['code', 'detail', 'weight', 'hidden', 'keywords'])) + ['key' => (string) $key];
        }

        if ($hidden !== []) {
            sort($hidden);
            $signals[] = [
                'code' => 'hidden_links',
                'detail' => count($hidden).' domain: '.implode(', ', array_slice($hidden, 0, 10)),
                'weight' => min(count($hidden) * (int) ($weights['hidden_links'] ?? 25), (int) ($weights['hidden_links_max'] ?? 75)),
                'hidden' => true,
            ];
        }

        if ($base !== null && ($surge = $this->surge($base, $baseStats, $now, $config)) !== null) {
            $signals[] = $surge + ['code' => 'link_surge', 'weight' => (int) ($weights['link_surge'] ?? 30), 'hidden' => false];
        }

        if ($this->hiddenRatio($now->stats, $config) && ($base === null || ! $this->hiddenRatio($baseStats, $config))) {
            $signals[] = [
                'code' => 'hidden_links',
                'detail' => sprintf('%d dari %d link keluar tersembunyi (%d%%)', $now->stats['hidden_external_links'], $now->stats['external_links'], round($now->stats['hidden_ratio'] * 100)),
                'weight' => (int) ($weights['hidden_ratio'] ?? 30),
                'hidden' => true,
            ];
        }

        usort($signals, fn (array $a, array $b) => $b['weight'] <=> $a['weight']);
        $score = min(999, array_sum(array_column($signals, 'weight')));
        $incidentScore = min(999, array_sum(array_map(
            fn (array $s) => in_array($s['code'], self::INFO_ONLY_CODES, true) ? 0 : $s['weight'],
            $signals,
        )));
        $severity = $this->severity($score, $config);
        $incidentSeverity = $this->severity($incidentScore, $config);

        return [
            'score' => $score,
            'severity' => $severity,
            'incident_severity' => $incidentSeverity,
            'seed' => $base === null,
            'added' => $added,
            'removed' => $removed,
            'signals' => array_slice($signals, 0, max(1, (int) ($config['max_signals'] ?? 50))),
            // judol_link is not ours to freeze on: no injection finding to mark legitimate.
            'absorb' => in_array($incidentSeverity, ['none', 'info'], true) && $now->complete,
        ];
    }

    public function severity(int $score, ?array $config = null): string
    {
        $thresholds = (array) (($config ?? (array) config('defacement.injection', []))['severity'] ?? []);

        return match (true) {
            $score >= (int) ($thresholds['critical'] ?? 60) => 'critical',
            $score >= (int) ($thresholds['warning'] ?? 30) => 'warning',
            $score > 0 => 'info',
            default => 'none',
        };
    }

    /**
     * Many new external domains at once and at least surge_factor x the baseline count.
     *
     * @return array{detail: string}|null
     */
    protected function surge(array $base, array $baseStats, InjectionProfile $now, array $config): ?array
    {
        $domains = function (array $items): array {
            $found = [];

            foreach (array_keys($items) as $key) {
                if (preg_match('/^(?:xd|hlnk)\|(.+)$/', (string) $key, $m)) {
                    $found[$m[1]] = true;
                }
            }

            return array_keys($found);
        };
        $new = count(array_diff($domains($now->items), $domains($base)));
        $before = (int) ($baseStats['external_domains'] ?? 0);
        $after = (int) ($now->stats['external_domains'] ?? 0);

        if ($new < (int) ($config['surge_min_new_domains'] ?? 10) || $after < (float) ($config['surge_factor'] ?? 2) * max(1, $before)) {
            return null;
        }

        return ['detail' => sprintf('%d domain baru (%d → %d domain eksternal)', $new, $before, $after)];
    }

    protected function hiddenRatio(array $stats, array $config): bool
    {
        return (float) ($stats['hidden_ratio'] ?? 0) >= (float) ($config['hidden_ratio'] ?? 0.3)
            && (int) ($stats['hidden_external_links'] ?? 0) >= (int) ($config['hidden_ratio_min_links'] ?? 5);
    }
}
