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

];
