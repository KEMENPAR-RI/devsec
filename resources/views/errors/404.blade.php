@extends('errors.layout', [
    'code' => 404,
    'title' => 'Halaman tidak ditemukan',
    'message' => 'Alamat yang Anda buka tidak ada, atau halamannya sudah dipindahkan. Periksa kembali tautannya.',
])

@section('mark-glyph')
    {{-- Magnifier: "we looked for it". --}}
    <circle cx="11" cy="11.5" r="2.6" />
    <path d="M13 13.5l2 2" />
@endsection
