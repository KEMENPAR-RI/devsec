<div class="mx-auto flex max-w-7xl flex-col gap-6">
    {{-- Header --}}
    <div class="enter flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between print:hidden">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-zinc-900 dark:text-white">Laporan</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Rekap bulanan uptime, insiden, dan temuan per situs - siap dicetak atau diunduh sebagai CSV.</p>
        </div>

        <div class="flex shrink-0 flex-wrap items-center gap-2">
            <a
                href="{{ route('laporan.unduh', ['bulan' => $month, 'situs' => $website ?: null]) }}"
                class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-200 px-3.5 py-2 text-sm font-medium text-zinc-600 transition hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800"
            >
                <x-phosphor-download-simple class="h-4 w-4" />
                Unduh CSV
            </a>
            <button
                type="button"
                onclick="window.print()"
                class="inline-flex items-center gap-1.5 rounded-lg bg-accent-600 px-3.5 py-2 text-sm font-semibold text-white transition hover:bg-accent-700"
            >
                <x-phosphor-printer class="h-4 w-4" />
                Cetak / PDF
            </button>
        </div>
    </div>

    {{-- Filters. Hidden on paper: a printed report should state its period, not
    show the controls that picked it. --}}
    <div class="enter flex flex-col gap-3 rounded-2xl border border-zinc-200 bg-white p-4 sm:flex-row sm:items-center dark:border-zinc-800 dark:bg-zinc-900 print:hidden">
        <select wire:model.live="month" class="rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-700 focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">
            @foreach ($monthOptions as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>

        <select wire:model.live="website" class="rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-700 focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">
            <option value="">Semua situs</option>
            @foreach ($websites as $site)
                <option value="{{ $site->uuid }}">{{ $site->name }}</option>
            @endforeach
        </select>
    </div>

    {{-- Printed header --}}
    <div class="hidden print:block">
        <h1 class="text-xl font-semibold">Laporan Pemantauan Situs</h1>
        <p class="text-sm">{{ config('app.name') }}</p>
    </div>

    <p class="enter text-sm text-zinc-500 dark:text-zinc-400">
        Periode <span class="font-medium text-zinc-700 dark:text-zinc-200">{{ $report['period']['start']->translatedFormat('d F Y') }} - {{ $report['period']['end']->translatedFormat('d F Y') }}</span>.
        Dibuat {{ \App\Support\Dates::display(now()) }}.
    </p>

    {{-- Summary --}}
    @php $totals = $report['totals']; @endphp
    <div class="enter grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
        @foreach ([
            ['Situs Dipantau', $totals['situs']],
            ['Rata-rata Uptime', $totals['uptime_rata'] !== null ? $totals['uptime_rata'].'%' : 'Belum ada data'],
            ['Total Insiden', $totals['insiden_total']],
            ['Situs Tanpa Insiden', $totals['situs_tanpa_insiden']],
        ] as [$label, $value])
            <div class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
                <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ $label }}</p>
                <p class="mt-1 text-xl font-semibold text-zinc-900 dark:text-white">{{ $value }}</p>
            </div>
        @endforeach
    </div>

    {{-- Per-site table. Scrolls inside its own box so the page never scrolls
    sideways on a phone. --}}
    <div class="enter overflow-x-auto rounded-2xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
        <table class="w-full min-w-[56rem] text-left text-sm">
            <thead class="border-b border-zinc-100 text-xs uppercase tracking-wide text-zinc-500 dark:border-zinc-800 dark:text-zinc-400">
                <tr>
                    <th scope="col" class="px-4 py-3 font-medium">Situs</th>
                    <th scope="col" class="px-4 py-3 text-right font-medium">Uptime</th>
                    <th scope="col" class="px-4 py-3 text-right font-medium">Rata Respons</th>
                    <th scope="col" class="px-4 py-3 text-right font-medium">Insiden</th>
                    <th scope="col" class="px-4 py-3 text-center font-medium">Keamanan</th>
                    <th scope="col" class="px-4 py-3 font-medium">Domain s/d</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @forelse ($report['rows'] as $row)
                    <tr>
                        <td class="px-4 py-3">
                            <p class="font-medium text-zinc-900 dark:text-white">{{ $row['nama'] }}</p>
                            <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ $row['url'] }}</p>
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums">
                            @if ($row['uptime'] === null)
                                <span class="text-zinc-400 dark:text-zinc-500">belum ada data</span>
                            @else
                                <span @class([
                                    'font-medium',
                                    'text-emerald-600 dark:text-emerald-400' => $row['uptime'] >= 99,
                                    'text-amber-600 dark:text-amber-400' => $row['uptime'] < 99 && $row['uptime'] >= 95,
                                    'text-red-600 dark:text-red-400' => $row['uptime'] < 95,
                                ])>{{ $row['uptime'] }}%</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums text-zinc-600 dark:text-zinc-300">
                            {{ $row['rata_respons_ms'] !== null ? number_format($row['rata_respons_ms']).' ms' : '-' }}
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums text-zinc-600 dark:text-zinc-300">
                            {{ $row['insiden_total'] }}
                            @if ($row['insiden_total'] > 0)
                                <span class="block text-xs text-zinc-400 dark:text-zinc-500">
                                    {{ collect([
                                        'down' => $row['insiden_downtime'],
                                        'serangan' => $row['insiden_serangan'],
                                        'ssl' => $row['insiden_ssl'],
                                        'dns' => $row['insiden_dns'],
                                        'domain' => $row['insiden_domain'],
                                    ])->filter()->map(fn ($n, $k) => "{$n} {$k}")->implode(', ') }}
                                </span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-center">
                            @if ($row['nilai_keamanan'])
                                <span class="font-semibold text-zinc-900 dark:text-white">{{ $row['nilai_keamanan'] }}</span>
                                <span class="text-xs text-zinc-400 dark:text-zinc-500">({{ $row['skor_keamanan'] }})</span>
                            @else
                                <span class="text-zinc-400 dark:text-zinc-500">-</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-zinc-600 dark:text-zinc-300">
                            {{ $row['domain_kedaluwarsa']?->translatedFormat('d M Y') ?? '-' }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-12 text-center text-sm text-zinc-500 dark:text-zinc-400">
                            Belum ada situs untuk dilaporkan.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <p class="text-xs text-zinc-400 dark:text-zinc-500">
        Uptime dihitung dari seluruh pengecekan yang tercatat pada periode ini, termasuk yang berjalan selama jendela pemeliharaan - angkanya sengaja tidak dipoles.
        Situs tanpa pengecekan pada periode tersebut ditulis "belum ada data", bukan 100%.
    </p>
</div>
