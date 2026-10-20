<?php

namespace App\Services\Monitoring;

use App\Models\Setting;

/**
 * Admin-tunable defacement thresholds: Setting rows first, config/defacement.php
 * as the default until saved.
 */
class DefacementSettings
{
    public function replacePercent(): int
    {
        return $this->int('defacement_replace_percent', 'defacement.replace_percent', 60);
    }

    public function replaceWithoutTitlePercent(): int
    {
        return max($this->replacePercent(), (int) config('defacement.replace_without_title_percent', 90));
    }

    public function shrinkPercent(): int
    {
        return $this->int('defacement_shrink_percent', 'defacement.shrink_percent', 85);
    }

    public function minBaselineChars(): int
    {
        return $this->int('defacement_min_baseline_chars', 'defacement.min_baseline_chars', 300);
    }

    /**
     * @param  array{replace_percent: int, shrink_percent: int, min_baseline_chars: int}  $values
     */
    public function save(array $values): void
    {
        Setting::set('defacement_replace_percent', (string) (int) $values['replace_percent']);
        Setting::set('defacement_shrink_percent', (string) (int) $values['shrink_percent']);
        Setting::set('defacement_min_baseline_chars', (string) (int) $values['min_baseline_chars']);
    }

    protected function int(string $key, string $config, int $default): int
    {
        $value = Setting::get($key);

        return $value !== null && $value !== '' ? (int) $value : (int) config($config, $default);
    }
}
