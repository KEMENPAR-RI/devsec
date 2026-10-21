@props(['code' => null])

{{-- Label for a risk / defacement reason code from DefacementMonitor::INJECTION_LABELS; unknown codes print as-is (escaped). --}}
@php
    $key = (string) ($code ?? '');
    $text = \App\Services\Monitoring\DefacementMonitor::INJECTION_LABELS[$key] ?? ($key ?: 'Lainnya');
@endphp
<span {{ $attributes }}>{{ $text }}</span>
