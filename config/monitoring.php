<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Masa Simpan Hasil Cek Website
    |--------------------------------------------------------------------------
    |
    | Berapa hari baris mentah `website_checks` disimpan sebelum dipangkas oleh
    | perintah `websites:prune-checks` yang berjalan harian (lihat
    | routes/console.php). Sebelum dihapus, setiap hari diringkas ke tabel
    | `website_check_daily` yang disimpan permanen, sehingga Laporan bulan lama
    | tetap terisi.
    |
    */
    'checks_retention_days' => (int) env('MONITORING_CHECKS_RETENTION_DAYS', 90),

    /*
    |--------------------------------------------------------------------------
    | Rentang IP Privat yang Boleh Dipantau
    |--------------------------------------------------------------------------
    |
    | Penjaga SSRF menolak situs yang domainnya resolve ke IP privat dari server
    | ini. Domain kemenpar.go.id resolve ke alamat intranet lewat DNS internal,
    | jadi rentang intranet itu diizinkan di sini (CIDR IPv4 dipisah koma,
    | /8 sampai /32). Loopback, link-local/metadata cloud, dan CGNAT tetap
    | diblokir apa pun isinya. Isi MONITORING_ALLOWED_PRIVATE_CIDRS= (kosong)
    | untuk mematikan allowlist sepenuhnya.
    |
    */
    'allowed_private_cidrs' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('MONITORING_ALLOWED_PRIVATE_CIDRS', '10.10.100.0/24,192.168.55.0/24,172.17.100.0/24')),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Cek Ulang Situs Offline
    |--------------------------------------------------------------------------
    |
    | Situs yang sudah down 24 jam atau lebih dicek setiap sekian menit (5-60;
    | di luar itu atau non-angka jatuh ke 10) sampai online lagi. Sebelum 24 jam
    | dan setelah online, situs memakai interval normalnya.
    |
    */
    'offline_recheck_minutes' => filter_var(env('MONITORING_OFFLINE_RECHECK_MINUTES', 10), FILTER_VALIDATE_INT, [
        'options' => ['default' => 10, 'min_range' => 5, 'max_range' => 60],
    ]),

    /*
    |--------------------------------------------------------------------------
    | Sertifikat SSL
    |--------------------------------------------------------------------------
    |
    | Ambang hari sebelum kedaluwarsa untuk insiden warning/critical, dan file
    | CA bundle untuk memverifikasi rantai sertifikat. Bila MONITORING_SSL_CAFILE
    | kosong, dipakai bundle bawaan OpenSSL di server.
    |
    */
    'ssl' => [
        'warning_days' => (int) env('MONITORING_SSL_WARNING_DAYS', 14),
        'critical_days' => (int) env('MONITORING_SSL_CRITICAL_DAYS', 3),
        // Seconds per TLS handshake (connect + read).
        'timeout' => (int) env('MONITORING_SSL_TIMEOUT', 5),
        'cafile' => env('MONITORING_SSL_CAFILE') ?: (function_exists('openssl_get_cert_locations')
            ? (openssl_get_cert_locations()['default_cert_file'] ?? null)
            : null),
    ],

    /*
    |--------------------------------------------------------------------------
    | Masa Berlaku Domain
    |--------------------------------------------------------------------------
    |
    | Lebih panjang dari ambang SSL: perpanjangan domain .go.id melewati proses
    | pengadaan yang tidak selesai dalam dua minggu.
    |
    */
    'domain' => [
        'warning_days' => (int) env('MONITORING_DOMAIN_WARNING_DAYS', 45),
        'critical_days' => (int) env('MONITORING_DOMAIN_CRITICAL_DAYS', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Nama Antrean
    |--------------------------------------------------------------------------
    |
    | Worker: checks-high,checks,default. Notifikasi email dari pengecekan ikut
    | checks-high.
    |
    */
    'queues' => [
        'checks_high' => env('MONITORING_QUEUE_CHECKS_HIGH', 'checks-high'),
        'checks' => env('MONITORING_QUEUE_CHECKS', 'checks'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Batas Ukuran Respons
    |--------------------------------------------------------------------------
    |
    | max_response_bytes: halaman yang lebih besar ditolak/dipotong sebelum
    | di-parse (juga berlaku untuk hasil dekompresi gzip).
    |
    */
    'max_response_bytes' => (int) env('MONITORING_MAX_RESPONSE_BYTES', 5 * 1024 * 1024),

];
