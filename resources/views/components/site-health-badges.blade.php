@props(['website', 'columns' => false])

{{--
    Compact SSL / domain / grade / incident chips for one site row.
    Reads only columns and withCount values already on $website - no queries.
    columns: fixed-width cells so rows line up in the desktop column group;
    otherwise chips flow inline and empty ones are skipped.
--}}
@php
    $tones = [
        'red' => 'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-400',
        'amber' => 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-400',
        'green' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400',
        'muted' => 'bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400',
    ];
    // Same calendar-day count and thresholds as the show page's certificate panel.
    $expiry = function (?int $days, int $red, int $amber) {
        return match (true) {
            $days === null => ['—', 'muted'],
            $days < 0 => ['Habis', 'red'],
            default => [$days.'h', $days <= $red ? 'red' : ($days <= $amber ? 'amber' : 'green')],
        };
    };
    $until = fn (?int $days, $date) => match (true) {
        $days < 0 => 'kedaluwarsa '.abs($days).' hari lalu',
        $days === 0 => 'berakhir hari ini',
        default => $days.' hari lagi',
    }.' ('.\App\Support\Dates::display($date).')';

    $isHttps = str_starts_with(strtolower($website->url), 'https://');
    $sslDays = $isHttps ? \App\Support\Dates::daysUntil($website->ssl_expires_at) : null;
    [$sslText, $sslTone] = $expiry($sslDays, (int) config('monitoring.ssl.critical_days'), (int) config('monitoring.ssl.warning_days'));
    $sslTitle = match (true) {
        ! $isHttps => 'SSL: situs tidak memakai HTTPS',
        $sslDays === null => 'SSL: belum dicek',
        default => 'SSL '.$until($sslDays, $website->ssl_expires_at),
    };
    $sslIssues = $isHttps ? array_filter([
        $website->ssl_hostname_match === false ? 'nama host tidak cocok dengan sertifikat' : null,
        $website->ssl_chain_valid === false ? 'rantai sertifikat tidak valid' : null,
        $website->ssl_error ? 'galat: '.$website->ssl_error : null,
    ]) : [];
    if ($sslIssues) {
        $sslTitle .= ' - Peringatan: '.implode('; ', $sslIssues);
    }

    $domainDays = \App\Support\Dates::daysUntil($website->domain_expires_at);
    [$domainText, $domainTone] = $expiry($domainDays, (int) config('monitoring.domain.critical_days', 3), (int) config('monitoring.domain.warning_days'));
    $domainTitle = $domainDays === null ? 'Domain: tanggal kedaluwarsa belum diketahui' : 'Domain '.$until($domainDays, $website->domain_expires_at);

    $grade = $website->security_grade;
    $gradeTone = match ($grade) {
        'A' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400',
        'B' => 'bg-lime-50 text-lime-700 dark:bg-lime-500/10 dark:text-lime-400',
        'C' => 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-400',
        'D' => 'bg-orange-50 text-orange-700 dark:bg-orange-500/10 dark:text-orange-400',
        null => $tones['muted'],
        default => 'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-400',
    };
    $gradeTitle = $grade ? "Nilai keamanan header & TLS: {$grade} ({$website->security_score}/100)" : 'Nilai keamanan: belum dinilai';

    $openIncidents = (int) ($website->open_incidents_count ?? 0);

    $chip = 'inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 font-mono text-[11px] font-medium tabular-nums whitespace-nowrap';
    $cell = $columns ? 'w-16 justify-center' : '';
@endphp

<span {{ $attributes->merge(['class' => 'items-center gap-1.5']) }}>
    <span title="{{ $sslTitle }}" class="{{ $chip }} {{ $columns ? 'w-[4.5rem] justify-center' : '' }} {{ $tones[$sslTone] }}">
        <x-phosphor-lock-simple class="h-3 w-3 shrink-0" aria-hidden="true" />
        <span class="sr-only">SSL:</span>{{ $sslText }}
        @if ($sslIssues)
            <x-phosphor-warning class="h-3 w-3 shrink-0 text-red-600 dark:text-red-400" aria-label="Peringatan sertifikat" />
        @endif
    </span>

    <span title="{{ $domainTitle }}" class="{{ $chip }} {{ $cell }} {{ $tones[$domainTone] }}">
        <x-phosphor-globe-simple class="h-3 w-3 shrink-0" aria-hidden="true" />
        <span class="sr-only">Domain:</span>{{ $domainText }}
    </span>

    <span title="{{ $gradeTitle }}" class="inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-md text-[11px] font-bold {{ $gradeTone }}">
        <span class="sr-only">Nilai keamanan:</span>{{ $grade ?? '—' }}
    </span>

    @can('insiden.view')
        @if ($openIncidents > 0)
            <span title="{{ $openIncidents }} insiden terbuka" class="{{ $chip }} {{ $columns ? 'w-11 justify-center' : '' }} {{ $tones['red'] }}">
                <x-phosphor-bell-ringing class="h-3 w-3 shrink-0" aria-hidden="true" />
                <span class="sr-only">Insiden terbuka:</span>{{ $openIncidents }}
            </span>
        @elseif ($columns)
            <span class="w-11" aria-hidden="true"></span>
        @endif
    @endcan
</span>
