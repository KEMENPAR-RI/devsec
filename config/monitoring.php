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
    | Situs Lambat (Warning) dan Situs Tanpa Respons
    |--------------------------------------------------------------------------
    |
    | connect_timeout_seconds: batas waktu membuka koneksi (5-10, default 10;
    | 5 detik dulu memicu false down di hosting instansi).
    | warning_recheck_seconds: situs berstatus Warning dicek ulang tiap sekian
    | detik (15-30, default 20) sampai pulih atau menjadi Down.
    | no_response: situs yang tidak merespons sama sekali (connect timeout,
    | gagal konek, DNS) dicoba ulang sebanyak `retries` kali (0-2) dengan jeda
    | `retry_delay_seconds` (5-10) sebelum dianggap Down. Nilai di luar rentang
    | jatuh ke default.
    |
    */
    'connect_timeout_seconds' => filter_var(env('MONITORING_CONNECT_TIMEOUT', 10), FILTER_VALIDATE_INT, [
        'options' => ['default' => 10, 'min_range' => 5, 'max_range' => 10],
    ]),

    'warning_recheck_seconds' => filter_var(env('MONITORING_WARNING_RECHECK_SECONDS', 20), FILTER_VALIDATE_INT, [
        'options' => ['default' => 20, 'min_range' => 15, 'max_range' => 30],
    ]),

    // Re-cek Warning paling banyak sekian situs per putaran 15 detik (1-20, default 5),
    // yang paling lama belum dicek lebih dulu; masuk antrean prioritas rendah.
    'warning_recheck_max_per_tick' => filter_var(env('MONITORING_WARNING_RECHECK_MAX', 5), FILTER_VALIDATE_INT, [
        'options' => ['default' => 5, 'min_range' => 1, 'max_range' => 20],
    ]),

    'no_response' => [
        'retries' => filter_var(env('MONITORING_NORESP_RETRIES', 2), FILTER_VALIDATE_INT, [
            'options' => ['default' => 2, 'min_range' => 0, 'max_range' => 2],
        ]),
        'retry_delay_seconds' => filter_var(env('MONITORING_NORESP_RETRY_DELAY', 8), FILTER_VALIDATE_INT, [
            'options' => ['default' => 8, 'min_range' => 5, 'max_range' => 10],
        ]),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cek Konektivitas Server Monitor
    |--------------------------------------------------------------------------
    |
    | Sebelum situs tanpa respons ditandai Down, server ini memastikan dirinya
    | sendiri masih terhubung ke internet lewat koneksi TCP ke salah satu target
    | (host:port, dipisah koma). Bila semua gagal, hasil cek tidak dicatat dan
    | dashboard menampilkan "Monitor offline". Hasil probe di-cache sekian detik.
    | Bila target probe gagal tetapi situs lain tetap merespons (egress ke target
    | diblokir firewall), monitor tetap dianggap online selama ada respons situs
    | dalam `recent_response_seconds` terakhir, dan peringatan dicatat di log.
    |
    */
    'connectivity' => [
        'enabled' => filter_var(env('MONITORING_CONNECTIVITY_CHECK', true), FILTER_VALIDATE_BOOL),
        'targets' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('MONITORING_CONNECTIVITY_TARGETS', '1.1.1.1:443,8.8.8.8:443')),
        ))),
        'timeout_seconds' => filter_var(env('MONITORING_CONNECTIVITY_TIMEOUT', 3), FILTER_VALIDATE_INT, [
            'options' => ['default' => 3, 'min_range' => 1, 'max_range' => 10],
        ]),
        'cache_seconds' => filter_var(env('MONITORING_CONNECTIVITY_CACHE_SECONDS', 30), FILTER_VALIDATE_INT, [
            'options' => ['default' => 30, 'min_range' => 10, 'max_range' => 300],
        ]),
        'recent_response_seconds' => filter_var(env('MONITORING_CONNECTIVITY_RECENT_SECONDS', 120), FILTER_VALIDATE_INT, [
            'options' => ['default' => 120, 'min_range' => 30, 'max_range' => 600],
        ]),
    ],

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
    | Worker: checks-high,checks,default,checks-low. Notifikasi email dari
    | pengecekan ikut checks-high. Re-cek situs Warning masuk checks-low agar
    | situs lambat tidak menahan cek situs lain.
    |
    */
    'queues' => [
        'checks_high' => env('MONITORING_QUEUE_CHECKS_HIGH', 'checks-high'),
        'checks' => env('MONITORING_QUEUE_CHECKS', 'checks'),
        'checks_low' => env('MONITORING_QUEUE_CHECKS_LOW', 'checks-low'),
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
