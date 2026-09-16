<?php

namespace App\Services\Reporting;

use App\Models\Incident;
use App\Models\JudolDetection;
use App\Models\Website;
use App\Models\WebsiteCheck;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The per-period summary an institution actually files.
 *
 * Built from aggregate queries rather than by loading checks into PHP: a month
 * of minute-by-minute probes across dozens of sites is hundreds of thousands of
 * rows, and the report has to render on a shared-hosting box.
 *
 * Every figure is derived from the same tables the dashboard reads, so a report
 * and the screen it was generated from can never disagree.
 */
class MonthlyReport
{
    /**
     * One row per site, plus the totals line.
     *
     * @return array{rows: Collection<int, array<string, mixed>>, totals: array<string, mixed>, period: array{start: Carbon, end: Carbon}}
     */
    public function build(Carbon $start, Carbon $end, ?Website $only = null): array
    {
        $start = $start->copy()->startOfDay();
        $end = $end->copy()->endOfDay();

        $websites = Website::query()
            ->when($only, fn ($q) => $q->whereKey($only->id))
            ->orderBy('name')
            ->get();

        $uptime = $this->uptimeByWebsite($start, $end);
        $incidents = $this->incidentsByWebsite($start, $end);
        $judol = $this->judolByWebsite($start, $end);

        $rows = $websites->map(function (Website $website) use ($uptime, $incidents, $judol) {
            $stats = $uptime[$website->id] ?? null;
            $byType = $incidents[$website->id] ?? [];

            return [
                'website' => $website,
                'nama' => $website->name,
                'url' => $website->url,
                // No checks in the period is not 100% uptime - it is "belum ada
                // data", and reporting it as perfect would be a lie that flatters.
                'uptime' => $stats && $stats->total > 0 ? round($stats->up / $stats->total * 100, 2) : null,
                'total_cek' => (int) ($stats->total ?? 0),
                'rata_respons_ms' => $stats && $stats->avg_ms ? (int) round($stats->avg_ms) : null,
                'insiden_total' => array_sum($byType),
                'insiden_downtime' => $byType['downtime'] ?? 0,
                'insiden_serangan' => $byType['attack'] ?? 0,
                'insiden_ssl' => $byType['ssl'] ?? 0,
                'insiden_dns' => $byType['dns'] ?? 0,
                'insiden_domain' => $byType['domain'] ?? 0,
                'temuan_judol' => $judol[$website->id] ?? 0,
                'nilai_keamanan' => $website->security_grade,
                'skor_keamanan' => $website->security_score,
                'domain_kedaluwarsa' => $website->domain_expires_at,
            ];
        });

        return [
            'rows' => $rows,
            'totals' => $this->totals($rows),
            'period' => ['start' => $start, 'end' => $end],
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    protected function totals(Collection $rows): array
    {
        // Averaged only over sites that actually have data, so a newly added site
        // does not drag the institution-wide figure down to zero.
        $measured = $rows->filter(fn (array $row) => $row['uptime'] !== null);

        return [
            'situs' => $rows->count(),
            'situs_terukur' => $measured->count(),
            'uptime_rata' => $measured->isEmpty() ? null : round($measured->avg('uptime'), 2),
            'insiden_total' => $rows->sum('insiden_total'),
            'temuan_judol' => $rows->sum('temuan_judol'),
            'situs_tanpa_insiden' => $rows->where('insiden_total', 0)->count(),
        ];
    }

    /**
     * @return array<int, object>
     */
    protected function uptimeByWebsite(Carbon $start, Carbon $end): array
    {
        return WebsiteCheck::query()
            ->selectRaw('website_id, COUNT(*) as total, SUM(is_up) as up, AVG(response_time_ms) as avg_ms')
            ->whereBetween('checked_at', [$start, $end])
            ->groupBy('website_id')
            ->get()
            ->keyBy('website_id')
            ->all();
    }

    /**
     * @return array<int, array<string, int>>
     */
    protected function incidentsByWebsite(Carbon $start, Carbon $end): array
    {
        return Incident::query()
            ->selectRaw('website_id, type, COUNT(*) as total')
            ->whereBetween('detected_at', [$start, $end])
            ->groupBy('website_id', 'type')
            ->get()
            ->groupBy('website_id')
            ->map(fn (Collection $rows) => $rows->pluck('total', 'type')->map(fn ($n) => (int) $n)->all())
            ->all();
    }

    /**
     * @return array<int, int>
     */
    protected function judolByWebsite(Carbon $start, Carbon $end): array
    {
        return JudolDetection::query()
            ->selectRaw('website_id, COUNT(*) as total')
            ->whereBetween('detected_at', [$start, $end])
            ->groupBy('website_id')
            ->pluck('total', 'website_id')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    /**
     * The same rows as a CSV.
     *
     * Semicolon-separated with a UTF-8 BOM: Indonesian Windows installs default
     * Excel to a semicolon list separator, and without the BOM every accented
     * name arrives as mojibake. A CSV nobody can open in Excel is not an export.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    public function toCsv(Collection $rows): string
    {
        $header = [
            'Nama Situs', 'URL', 'Uptime (%)', 'Total Cek', 'Rata-rata Respons (ms)',
            'Insiden Total', 'Down Time', 'Serangan', 'SSL', 'DNS', 'Domain',
            'Temuan Judol', 'Nilai Keamanan', 'Skor Keamanan', 'Domain Kedaluwarsa',
        ];

        $lines = collect([$this->csvLine($header)]);

        foreach ($rows as $row) {
            $lines->push($this->csvLine([
                $row['nama'],
                $row['url'],
                $row['uptime'] ?? 'belum ada data',
                $row['total_cek'],
                $row['rata_respons_ms'] ?? '-',
                $row['insiden_total'],
                $row['insiden_downtime'],
                $row['insiden_serangan'],
                $row['insiden_ssl'],
                $row['insiden_dns'],
                $row['insiden_domain'],
                $row['temuan_judol'],
                $row['nilai_keamanan'] ?? '-',
                $row['skor_keamanan'] ?? '-',
                $row['domain_kedaluwarsa']?->format('Y-m-d') ?? '-',
            ]));
        }

        return "\u{FEFF}".$lines->implode("\r\n")."\r\n";
    }

    /**
     * @param  array<int, mixed>  $values
     */
    protected function csvLine(array $values): string
    {
        return collect($values)
            ->map(function ($value) {
                $value = (string) $value;

                // Quote whenever the value could otherwise break the row apart.
                return preg_match('/[";\r\n]/', $value)
                    ? '"'.str_replace('"', '""', $value).'"'
                    : $value;
            })
            ->implode(';');
    }
}
