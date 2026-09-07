@extends('errors.layout', [
    'code' => 429,
    'title' => 'Terlalu banyak permintaan',
    'message' => 'Permintaan dari perangkat Anda dibatasi sementara karena terlalu sering. Tunggu sebentar, lalu coba lagi.',
    'footnote' => 'Pembatasan ini melindungi aplikasi dari percobaan masuk paksa.',
])

@section('mark-glyph')
    {{-- Pause: throttled, not blocked. --}}
    <path d="M10.6 10.4v3.4" />
    <path d="M13.4 10.4v3.4" />
@endsection
