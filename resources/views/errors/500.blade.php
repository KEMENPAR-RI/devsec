@extends('errors.layout', [
    'code' => 500,
    'title' => 'Terjadi kesalahan pada server',
    'message' => 'Ada gangguan di sisi kami saat memproses permintaan ini. Tim teknis sudah dapat melihatnya melalui catatan log.',
    'footnote' => 'Coba muat ulang beberapa saat lagi. Bila terus berulang, hubungi administrator.',
])

@section('mark-glyph')
    {{-- Alert: the only page here that reports a fault on our side. --}}
    <path d="M12 9.4v3" />
    <path d="M12 15.1v.05" />
@endsection
