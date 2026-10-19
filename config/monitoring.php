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
    | Pengecekan uptime dan crawl judol dipisah antrean supaya crawl yang lama
    | tidak menahan pengecekan. Worker A: checks-high,checks,default.
    | Worker B: scans. Notifikasi email dari pengecekan ikut checks-high.
    |
    */
    'queues' => [
        'checks_high' => env('MONITORING_QUEUE_CHECKS_HIGH', 'checks-high'),
        'checks' => env('MONITORING_QUEUE_CHECKS', 'checks'),
        'scans' => env('MONITORING_QUEUE_SCANS', 'scans'),
    ],

];
