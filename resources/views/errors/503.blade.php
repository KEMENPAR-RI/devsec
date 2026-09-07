@extends('errors.layout', [
    'code' => 503,
    'title' => 'Sedang dalam pemeliharaan',
    'message' => 'Aplikasi sementara tidak dapat diakses karena sedang diperbarui. Silakan kembali beberapa saat lagi.',
])

@section('mark-glyph')
    {{-- Wrench-ish diagonal: scheduled work, not a crash. --}}
    <path d="M10 14l4-4" />
    <path d="M13.4 9.4l1.2 1.2" />
@endsection
