{{--
    Laravel's CSRF/session-expiry status. Worth its own page rather than falling
    through to 500: nothing is broken, the fix is simply to load the form again,
    and saying so prevents a support ticket.
--}}
@extends('errors.layout', [
    'code' => 419,
    'title' => 'Sesi Anda telah berakhir',
    'message' => 'Halaman ini terbuka terlalu lama sehingga sesinya kedaluwarsa. Muat ulang halaman, lalu kirim ulang formulirnya.',
    'footnote' => 'Ini pengamanan biasa, bukan tanda ada masalah pada akun Anda.',
])

@section('mark-glyph')
    {{-- Clock hands: this one is about elapsed time. --}}
    <path d="M12 9.6v2.6l1.7 1.1" />
@endsection
