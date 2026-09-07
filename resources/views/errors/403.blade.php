@extends('errors.layout', [
    'code' => 403,
    'title' => 'Akses ditolak',
    'message' => 'Akun Anda tidak memiliki izin untuk membuka halaman ini. Hubungi administrator bila Anda merasa seharusnya punya akses.',
])

@section('mark-glyph')
    {{-- Padlock body inside the shield. --}}
    <rect x="9" y="11" width="6" height="5" rx="1" />
    <path d="M10.5 11V9.8a1.5 1.5 0 013 0V11" />
@endsection
