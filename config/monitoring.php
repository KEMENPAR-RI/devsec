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
    | Cek Ulang Situs Offline
    |--------------------------------------------------------------------------
    |
    | Situs yang sudah down 24 jam atau lebih dicek setiap sekian menit (5-60;
    | di luar itu atau non-angka jatuh ke 10) sampai online lagi. Sebelum 24 jam
    | dan setelah online, situs memakai interval normalnya. Tidak berlaku untuk
    | scan judol.
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

    /*
    |--------------------------------------------------------------------------
    | Riwayat Perubahan Konten & Snapshot Halaman
    |--------------------------------------------------------------------------
    |
    | Masa simpan riwayat perubahan konten (page_changes), snapshot halaman
    | (page_snapshots), dan temuan defacement tertutup, dipangkas harian oleh
    | `websites:prune-content`. Batas ukuran snapshot dan jumlah halaman per
    | situs menjaga storage/RAM aman di VPS kecil.
    |
    */
    'content_retention_days' => (int) env('MONITORING_CONTENT_RETENTION_DAYS', 90),
    'content_snapshot_chars' => 40000,
    'content_max_pages_per_site' => 150,
    'content_missing_after_crawls' => 3,

    // false: perubahan teks/elemen tanpa sinyal risiko tidak dicatat sebagai event (ADR 0002).
    'record_text_only_changes' => (bool) env('MONITORING_RECORD_TEXT_ONLY_CHANGES', false),

    /*
    |--------------------------------------------------------------------------
    | Detail Forensik Perubahan Konten
    |--------------------------------------------------------------------------
    |
    | Batas memori/storage forensik: halaman di atas forensics_max_html_bytes
    | tetap di-hash tetapi tidak disimpan mentah dan tanpa lokasi/cuplikan.
    | Salinan HTML mentah (gzip) disimpan di disk `local` di bawah
    | forensics_raw_path (ADR 0001). Payload per event dipangkas ke
    | forensics_max_items item dan forensics_max_payload_bytes byte JSON.
    |
    */
    'forensics_max_html_bytes' => 2 * 1024 * 1024,
    'forensics_max_raw_gz_bytes' => 1024 * 1024,
    'forensics_max_items' => 50,
    'forensics_snippet_chars' => 1000,
    'forensics_max_payload_bytes' => 65536,
    'forensics_max_elements' => 500,
    'forensics_raw_path' => 'page-bodies',

    /*
    |--------------------------------------------------------------------------
    | Batas Ukuran Respons & Pemeriksaan Ulang Judol
    |--------------------------------------------------------------------------
    |
    | max_response_bytes: halaman yang lebih besar ditolak/dipotong sebelum
    | di-parse (juga berlaku untuk hasil dekompresi gzip).
    | judol_recheck_sync_limit: jumlah deteksi yang diperiksa ulang langsung di
    | request; di atas itu pemeriksaan ulang dikirim ke antrean.
    |
    */
    'max_response_bytes' => (int) env('MONITORING_MAX_RESPONSE_BYTES', 5 * 1024 * 1024),
    'judol_recheck_sync_limit' => 300,

];
