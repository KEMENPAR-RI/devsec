<?php

namespace App\Livewire\Tv;

use App\Services\AppSettings;
use App\Services\Monitoring\TvBoard;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Public read-only wallboard; no public properties, so nothing a visitor sends shapes the query. */
#[Layout('components.layouts.tv')]
class Display extends Component
{
    public function render()
    {
        $data = app(TvBoard::class)->build();
        $slides = collect($data['kartu'])->chunk(16);
        $settings = app(AppSettings::class);

        return view('livewire.tv.display', [
            'diperbarui' => $data['diperbarui'],
            'latestEventAt' => $data['latest_event_at'],
            'latestEvent' => $data['latest_event'],
            'pollSeconds' => $settings->tvPollSeconds(),
            'timezone' => $settings->timezone(),
            // An empty board still renders one slide of placeholders.
            'slides' => $slides->isEmpty() ? collect([collect()]) : $slides,
        ]);
    }
}
