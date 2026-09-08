@props(['level', 'responseTime' => null])

{{--
    How hard the server appeared to be working on its last check, as a coloured
    pill. Colours and labels come from Website::LOAD_LEVELS.

    The tooltip carries the measurement the level was graded from, because the word
    alone ("Sedang") says nothing about how slow that actually was.
--}}
@php $config = \App\Models\Website::loadConfig($level); @endphp

<span
    title="{{ $responseTime ? 'Respons terakhir '.number_format($responseTime).'ms, dibandingkan rata-rata situs ini sendiri.' : 'Belum ada pengecekan yang berhasil untuk diukur.' }}"
    {{ $attributes->merge(['class' => 'inline-flex items-center justify-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium whitespace-nowrap ring-1 ring-inset '.$config['badge']]) }}
>
    <span class="h-1.5 w-1.5 shrink-0 rounded-full {{ $config['dot'] }}"></span>
    {{ $config['label'] }}
</span>
