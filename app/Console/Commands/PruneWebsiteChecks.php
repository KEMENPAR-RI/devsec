<?php

namespace App\Console\Commands;

use App\Models\WebsiteCheck;
use App\Models\WebsiteCheckDaily;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Rolls raw website checks up into permanent daily totals, then drops the raw
 * rows older than the retention window.
 *
 * Days are app-timezone wall-clock days of checked_at, the same boundaries
 * MonthlyReport filters on, so a report reads the same before and after a prune.
 */
class PruneWebsiteChecks extends Command
{
    protected $signature = 'websites:prune-checks
        {--days= : Override the retention window from config/monitoring.php}';

    protected $description = 'Roll up website checks into daily totals, then delete raw checks older than the retention window';

    public function handle(): int
    {
        // Only an absent option falls back to config; "0" or garbage must be refused, not replaced.
        $option = $this->option('days');
        $days = $option === null
            ? (int) config('monitoring.checks_retention_days', 90)
            : filter_var($option, FILTER_VALIDATE_INT);

        if ($days === false || $days < 1) {
            $this->error('Masa simpan harus minimal 1 hari.');

            return self::FAILURE;
        }

        // A huge value overflows subDays() into a future cutoff that would delete today's unrolled checks.
        if ($days > 3650) {
            $this->error('Masa simpan maksimal 3650 hari.');

            return self::FAILURE;
        }

        $rolled = $this->rollUp();

        // Whole days only, all before today: every one of them was just rolled up.
        $cutoff = today()->subDays($days);
        $deleted = 0;

        do {
            $batch = WebsiteCheck::query()->where('checked_at', '<', $cutoff)->orderBy('id')->limit(1000)->delete();
            $deleted += $batch;
        } while ($batch > 0);

        $this->info("{$rolled} ringkasan harian diperbarui; {$deleted} hasil cek lebih tua dari {$days} hari dihapus.");

        return self::SUCCESS;
    }

    /** Upserts one row per site per complete day, from the last rolled day (re-rolled) up to yesterday. */
    protected function rollUp(): int
    {
        $from = WebsiteCheckDaily::query()->max('day') ?? WebsiteCheck::query()->min('checked_at');

        if ($from === null) {
            return 0;
        }

        $today = today();
        $rolled = 0;

        for ($day = Carbon::parse($from)->startOfDay(); $day->lt($today); $day->addDay()) {
            $date = $day->toDateString();

            // Aggregated in SQL, one day at a time: a handful of rows per day reach PHP.
            $rows = WebsiteCheck::query()
                ->selectRaw('website_id, COUNT(*) as total_checks, SUM(CASE WHEN '.WebsiteCheck::UP_SQL.' THEN 1 ELSE 0 END) as up_checks, AVG(response_time_ms) as avg_ms, COUNT(response_time_ms) as timed, SUM(response_time_ms) as ms_sum')
                ->where('checked_at', '>=', $day)
                ->where('checked_at', '<', $day->copy()->addDay())
                ->groupBy('website_id')
                ->toBase()
                ->get()
                ->map(fn (object $row) => [
                    'website_id' => (int) $row->website_id,
                    'day' => $date,
                    'total_checks' => (int) $row->total_checks,
                    'up_checks' => (int) $row->up_checks,
                    'avg_response_ms' => $row->avg_ms === null ? null : (int) round((float) $row->avg_ms),
                    'timed_checks' => (int) $row->timed,
                    'response_ms_sum' => (int) $row->ms_sum,
                ])
                ->all();

            if ($rows !== []) {
                WebsiteCheckDaily::query()->upsert($rows, ['website_id', 'day'], ['total_checks', 'up_checks', 'avg_response_ms', 'timed_checks', 'response_ms_sum']);
                $rolled += count($rows);
            }
        }

        return $rolled;
    }
}
