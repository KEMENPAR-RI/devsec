<?php

namespace App\Livewire\Reports;

use App\Models\Website;
use App\Services\Reporting\MonthlyReport;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The periodic report page: uptime, incidents and findings for one month.
 *
 * Month-at-a-time rather than an arbitrary range picker, because that is the
 * unit institutional reporting is actually filed in - and a default that matches
 * what people need means the page is useful without configuring anything.
 */
#[Layout('components.layouts.app')]
class Index extends Component
{
    /** 'YYYY-MM' - the month being reported on. */
    #[Url]
    public string $month = '';

    /** '' for every site, otherwise a website uuid. */
    #[Url]
    public string $website = '';

    public function mount(): void
    {
        $this->authorize('laporan.view');

        // Last month by default: this month is still running, and a report of a
        // period that has not finished is the one nobody wants.
        $this->month = $this->month ?: now()->subMonthNoOverflow()->format('Y-m');
    }

    /** The months worth offering, newest first - two years is more than anyone files. */
    public function monthOptions(): array
    {
        return collect(range(0, 23))
            ->mapWithKeys(function (int $back) {
                $month = now()->startOfMonth()->subMonthsNoOverflow($back);

                return [$month->format('Y-m') => $month->translatedFormat('F Y')];
            })
            ->all();
    }

    public function selectedMonth(): Carbon
    {
        try {
            return Carbon::createFromFormat('Y-m', $this->month)->startOfMonth();
        } catch (\Throwable) {
            // A hand-edited query string must not break the page.
            return now()->subMonthNoOverflow()->startOfMonth();
        }
    }

    public function render()
    {
        $start = $this->selectedMonth();
        $site = $this->website ? Website::where('uuid', $this->website)->first() : null;

        $report = app(MonthlyReport::class)->build($start, $start->copy()->endOfMonth(), $site);

        return view('livewire.reports.index', [
            'report' => $report,
            'monthOptions' => $this->monthOptions(),
            'websites' => Website::orderBy('name')->get(['uuid', 'name']),
        ]);
    }
}
