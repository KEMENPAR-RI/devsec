<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Signature Defacer
    |--------------------------------------------------------------------------
    |
    | Frasa yang khas ditinggalkan pelaku defacement. Dicocokkan setelah
    | TextNormalizer::fold() sehingga variasi obfuscation (h4ck3d, H.A.C.K.E.D,
    | homoglyph) ikut tertangkap.
    |
    | - strong: kemunculannya (yang BELUM ada di baseline) langsung = defaced.
    | - weak: butuh sinyal lain (judul berubah ATAU konten terganti >=30%).
    |
    */
    'signatures' => [
        'strong' => [
            'hacked by',
            'defaced by',
            'deface by',
            'greetz',
            'greetings to',
            'pwned by',
            'cyber army',
            'security is low',
            'your security is',
        ],
        'weak' => [
            'hacked',
            'owned by',
            'was here',
            'touched by',
            'pwned',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Ambang Konten Terganti / Menyusut
    |--------------------------------------------------------------------------
    |
    | Default ditetapkan architect (K-ambang defacement). Bisa ditimpa admin
    | lewat Setting (defacement_replace_percent / _shrink_percent /
    | _min_baseline_chars), lihat DefacementSettings.
    |
    | - replace_percent: % konten baseline yang terganti + judul berubah.
    | - replace_without_title_percent: % terganti tanpa syarat judul berubah.
    | - shrink_percent: halaman menyusut hingga <= (100 - nilai)% baseline.
    | - min_baseline_chars: baseline minimal agar aturan (b)/(c) berlaku.
    |
    */
    'replace_percent' => 60,
    'replace_without_title_percent' => 90,
    'shrink_percent' => 85,
    'min_baseline_chars' => 300,

    /*
    |--------------------------------------------------------------------------
    | Pola Halaman Tantangan (WAF) & Placeholder Wajar
    |--------------------------------------------------------------------------
    |
    | challenge_patterns: halaman antar-bot berstatus 200 (Cloudflare dsb) yang
    | harus dilewati, bukan dianggap defacement/perubahan.
    | benign_patterns: halaman kecil yang wajar (maintenance, coming soon) yang
    | mengecualikan aturan "menyusut drastis" dan "konten terganti".
    |
    */
    'challenge_patterns' => [
        'just a moment',
        'checking your browser',
        'enable javascript and cookies',
        'please enable cookies',
        'attention required',
        'ddos protection by',
        'verifying you are human',
        'cf-ray',
        'ray id',
    ],

    'benign_patterns' => [
        'maintenance',
        'sedang dalam perbaikan',
        'coming soon',
        'segera hadir',
        'under construction',
        'error establishing a database connection',
        'service unavailable',
        'account suspended',
    ],

    /*
    |--------------------------------------------------------------------------
    | Fingerprint Risiko Struktural (Injeksi Kode / Link Tersembunyi)
    |--------------------------------------------------------------------------
    |
    | Lihat ADR 0002. Setiap halaman diringkas menjadi set kunci elemen
    | berisiko; skor = bobot kunci BARU dibanding baseline (kunci "absolut"
    | tetap dinilai walau baseline belum ada). Batas di bawah menjaga RAM/CPU
    | VPS 1 GB: halaman > max_html_bytes dan profil yang terpotong batas
    | dinilai sebagian (sinyal not_profiled / profile_truncated).
    |
    */
    'injection' => [
        'enabled' => true,
        'max_html_bytes' => (int) (1.5 * 1024 * 1024),
        'max_links' => 3000,
        'max_inline_scripts' => 300,
        'max_inline_script_bytes' => 100 * 1024,
        'max_profile_items' => 300,
        'max_url_length' => 2048,
        'max_signals' => 50,

        'severity' => ['critical' => 60, 'warning' => 30],

        'weights' => [
            'foreign_script' => 30,
            'obfuscated_js' => 40,
            'js_redirect' => 40,
            'cloaking' => 50,
            'document_write' => 10,
            'hidden_frame' => 40,
            'foreign_frame' => 10,
            'meta_refresh' => 50,
            'title_changed' => 10,
            'description_changed' => 5,
            'hidden_links' => 25,
            'hidden_links_max' => 75,
            'hidden_link_block' => 40,
            'judol_link' => 60,
            'link_surge' => 30,
            'hidden_ratio' => 30,
            'cryptominer' => 60,
            'not_profiled' => 30,
            'profile_truncated' => 30,
        ],

        // Link tersembunyi ke >= sekian domain dinilai walau belum ada baseline.
        'hidden_links_absolute' => 3,
        'hidden_block_min_links' => 3,
        'surge_min_new_domains' => 10,
        'surge_factor' => 2,
        'hidden_ratio' => 0.3,
        'hidden_ratio_min_links' => 5,
        'long_base64_chars' => 200,
        'hex_escape_min' => 50,

        // Domain skrip yang lazim (dicocokkan pada registrable domain + subdomain).
        'trusted_script_domains' => [
            'googletagmanager.com', 'google-analytics.com', 'googleapis.com', 'gstatic.com',
            'google.com', 'youtube.com', 'ytimg.com', 'doubleclick.net', 'facebook.net',
            'facebook.com', 'jquery.com', 'jsdelivr.net', 'cloudflare.com', 'cdnjs.com',
            'bootstrapcdn.com', 'unpkg.com', 'fontawesome.com', 'addtoany.com',
            'recaptcha.net', 'cloudflareinsights.com', 'tawk.to', 'whatsapp.com',
        ],

        'trusted_frame_domains' => [
            'youtube.com', 'youtube-nocookie.com', 'google.com', 'googletagmanager.com',
            'doubleclick.net', 'recaptcha.net', 'vimeo.com', 'facebook.com', 'instagram.com',
            'twitter.com', 'x.com', 'openstreetmap.org', 'spotify.com', 'soundcloud.com', 'tawk.to',
        ],

        // Penanda penambang kripto di URL skrip atau isi skrip inline.
        'miner_patterns' => [
            'coinhive' => 'Coinhive',
            'cryptonight' => 'CryptoNight',
            'coinimp' => 'CoinIMP',
            'webminepool' => 'WebMinePool',
        ],
    ],

];
