<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Kata Kunci Judi Online
    |--------------------------------------------------------------------------
    |
    | Daftar kata kunci yang umum dipakai spam SEO judi online (judol) saat
    | menyusup ke website yang diretas. Dicocokkan case-insensitive terhadap
    | judul, meta, isi teks halaman, DAN url-nya sendiri (lihat JudolScanner).
    |
    | Sengaja dijaga berupa frasa 2+ kata / nama brand yang cukup spesifik
    | (bukan kata umum satu suku seperti "slot" atau "zeus" sendirian), supaya
    | ambang batas 1 kecocokan tetap aman dari false positive.
    |
    */
    'keywords' => [
        // Slot - umum
        'slot gacor',
        'slot maxwin',
        'situs slot',
        'slot online',
        'slot deposit',
        'slot pulsa',
        'slot dana',
        'slot ovo',
        'slot gopay',
        'slot resmi',
        'slot terpercaya',
        'slot demo',
        'slot jackpot',
        'slot anti rungkad',
        'slot server thailand',
        'server thailand',
        'slot gampang menang',
        'situs slot gacor',
        'situs slot online terpercaya',
        'link slot gacor',
        'link alternatif slot',
        'daftar slot',
        'agen slot',
        'bandar slot',
        'gacor hari ini',
        'gacor malam ini',
        'pola gacor',
        'pola maxwin',
        'maxwin hari ini',
        'trik slot gacor',
        'bocoran slot',
        'bocoran rtp',
        'rtp live',
        'rtp slot',
        'toto slot',

        // Nama game slot populer yang sering dipakai judul halaman spam
        'mahjong ways',
        'starlight princess',
        'gates of olympus',
        'sweet bonanza',
        'wild bandito',
        'zeus vs hades',
        'aztec gems',

        // Togel
        'togel online',
        'togel terpercaya',
        'togel hari ini',
        'bandar togel',
        'situs togel',
        'toto togel',
        'togel 4d',
        'prediksi togel',
        'syair togel',
        'keluaran togel',
        'live draw togel',
        'hongkong pools',
        'singapore pools',

        // Judi bola / sportsbook
        'judi bola',
        'judi bola online',
        'taruhan bola',
        'taruhan bola online',
        'bandar bola',
        'agen bola',
        'mix parlay',
        'sbobet',

        // Casino & kartu
        'casino online',
        'live casino',
        'poker online',
        'domino qq',
        'ceme online',
        'baccarat online',

        // Umum / generik judi online
        'judi online',
        'judi slot',
        'agen judi',
        'situs judi terpercaya',
        'wd tercepat',
        'deposit tanpa potongan',
        'jackpot',

        // Bahasa asing (situs yang diretas sering "menitipkan" konten judi
        // berbahasa lain - paling sering Vietnam - lewat halaman doorway/SEO
        // spam, meski domain aslinya berbahasa Indonesia)
        'nhà cái',
        'nhà cái uy tín',
        'link vào nhà cái',
        'cá cược trực tuyến',
        'nổ hũ',
        'bắn cá',
        'đánh bài',
        'sòng bài',
        'cờ bạc',
        'casino trực tuyến',
        'game bài đổi thưởng',
    ],

    /*
    |--------------------------------------------------------------------------
    | Ambang Batas
    |--------------------------------------------------------------------------
    |
    | Jumlah kata kunci unik minimal yang harus cocok pada satu halaman
    | sebelum ditandai sebagai deteksi judol. Daftar kata kunci di atas sudah
    | spesifik (bukan kata umum), jadi 1 kecocokan pun cukup jadi sinyal kuat.
    |
    */
    'threshold' => 1,

    /*
    |--------------------------------------------------------------------------
    | Batas Crawl
    |--------------------------------------------------------------------------
    |
    | Pemindaian tidak berhenti di homepage saja: setiap situs di-crawl mulai
    | dari homepage + sitemap.xml, lalu mengikuti tautan internal (<a href>)
    | sampai batas berikut, supaya halaman yang disusupi tapi tidak terdaftar
    | di sitemap (mis. slot.html yang diam-diam ditambahkan) tetap ketemu.
    |
    */
    'max_sitemap_urls' => 50,
    'max_pages' => 60,
    'max_depth' => 3,

    /*
    |--------------------------------------------------------------------------
    | Klasifikasi AI (opsional)
    |--------------------------------------------------------------------------
    |
    | Lapisan kedua di atas keyword filter di atas: begitu sebuah halaman lolos
    | keyword match, Claude diminta mengonfirmasi/membantah temuan tersebut
    | (JudolAiClassifier) - baik dari teks halaman maupun gambar promosi yang
    | ada di halaman itu (banner "SLOT GACOR" dsb yang teksnya ada di gambar,
    | bukan di HTML, sehingga lolos dari keyword filter berbasis teks).
    |
    | Nonaktif secara default supaya tidak butuh ANTHROPIC_API_KEY untuk jalan.
    | Karena AI hanya dipanggil untuk halaman yang SUDAH lolos keyword filter,
    | biayanya nyaris nol di situs yang bersih.
    |
    */
    'ai' => [
        'enabled' => env('JUDOL_AI_ENABLED', false),
        'model' => env('JUDOL_AI_MODEL', 'claude-haiku-4-5'),

        // Ambang batas confidence (0-1) dari AI supaya temuan keyword dianggap
        // terkonfirmasi. Di bawah ini, dianggap false positive dan insiden tidak dibuat.
        'confidence_threshold' => 0.5,

        // Maksimum gambar per halaman yang dikirim ke Claude untuk dibaca (biaya naik
        // seiring jumlah gambar; kebanyakan halaman spam cukup terwakili oleh 1-3 gambar).
        'max_images' => 3,
    ],

];
