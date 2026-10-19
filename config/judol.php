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
    'max_pages' => 30,
    'max_depth' => 3,

    /*
    | Anggaran waktu satu crawl, dalam detik.
    |
    | max_pages membatasi JUMLAH halaman, bukan LAMANYA - dan yang ditegakkan
    | oleh queue adalah waktu. Situs lambat (~16 detik per halaman pada hosting
    | bersama) menembus $timeout job jauh sebelum halaman terakhir, lalu dibunuh
    | queue:work dengan SIGKILL: worker ikut mati, systemd me-restart, job yang
    | sama diambil lagi - dan gagal lagi, selamanya.
    |
    | Dengan batas ini crawl berhenti sendiri secara rapi: halaman yang sudah
    | dipindai tetap tersimpan dan dilaporkan, sisanya menunggu putaran
    | berikutnya. Harus lebih kecil dari ScanWebsiteForJudolJob::$timeout (300)
    | dengan margin untuk satu fetch terakhir yang sedang berjalan.
    */
    'max_seconds' => 120,

    /*
    | Rem dispatch websites:scan-judol per tick (tidak berlaku untuk --now/--sync):
    | lewati bila antrean scans sudah sepanjang max_queue, dan kirim paling
    | banyak max_dispatch_per_tick situs per menit.
    */
    'max_queue' => (int) env('JUDOL_MAX_QUEUE', 20),
    'max_dispatch_per_tick' => (int) env('JUDOL_MAX_DISPATCH_PER_TICK', 5),

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
    | Nonaktif secara default; diaktifkan & diberi API key dari menu Pengaturan
    | (JudolAiSettings). Nilai di sini hanya default bila belum pernah disimpan.
    | Karena AI hanya dipanggil untuk halaman yang SUDAH lolos keyword filter,
    | biayanya nyaris nol di situs yang bersih.
    |
    */
    'ai' => [
        'enabled' => false,
        'model' => 'claude-haiku-4-5',

        // Ambang batas confidence (0-1) dari AI supaya temuan keyword dianggap
        // terkonfirmasi. Di bawah ini, dianggap false positive dan insiden tidak dibuat.
        'confidence_threshold' => 0.5,

        // Maksimum gambar per halaman yang dikirim ke Claude untuk dibaca (biaya naik
        // seiring jumlah gambar; kebanyakan halaman spam cukup terwakili oleh 1-3 gambar).
        'max_images' => 3,

        // Batas waktu per request ke API Anthropic (detik) dan jumlah retry. Total
        // terburuk ~ timeout x (1 + max_retries) + jeda retry - harus jauh di bawah
        // ScanWebsiteForJudolJob::$timeout (300).
        'timeout' => (float) env('JUDOL_AI_TIMEOUT', 30),
        'max_retries' => (int) env('JUDOL_AI_MAX_RETRIES', 1),

        // Batas atas jeda retry (detik): header retry-after dari API dipotong ke nilai ini.
        'max_retry_delay' => (float) env('JUDOL_AI_MAX_RETRY_DELAY', 10),

        // Total waktu panggilan AI per satu scan (detik). Bila habis, sisa halaman
        // dinilai tanpa AI (seperti AI nonaktif) dan job tetap selesai.
        'budget_seconds' => (float) env('JUDOL_AI_BUDGET_SECONDS', 120),

        /*
        | Pemeriksaan tautan keluar (JudolLinkClassifier).
        |
        | Menangkap pola yang secara struktural TIDAK BISA dilihat keyword filter:
        | penyisipan backlink, di mana isi halaman dibiarkan utuh dan penyerang
        | hanya menanam <a href> tersembunyi ke situs judi miliknya. Tidak ada
        | satu pun kata kunci yang muncul, jadi halaman itu lolos sebagai bersih.
        |
        | Yang dinilai AI hanya nama domainnya, dan hasilnya di-cache per domain
        | selama 30 hari - jadi tautan footer yang berulang di 60 halaman cuma
        | ditanya sekali, dan pemindaian berikutnya biasanya nol panggilan API.
        */
        'links' => [
            'enabled' => true,

            // Maksimum domain unik per halaman yang dinilai. Halaman yang menaut ke
            // ratusan domain luar hampir selalu agregator/direktori, bukan korban
            // penyisipan - dan tidak sepadan untuk dinilai seluruhnya.
            'max_domains' => 40,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Host yang Tidak Perlu Dinilai
    |--------------------------------------------------------------------------
    |
    | Tujuan tautan yang praktis pasti sah, dicocokkan pada domain maupun
    | subdomainnya. Bukan daftar putih keamanan - hanya penghemat token, supaya
    | AI tidak ditanyai soal tombol berbagi Facebook di setiap halaman.
    |
    | Sengaja pendek: mustahil mendaftar seluruh web yang baik, dan AI memang
    | sudah diminta menjawab "bukan judi" saat ragu.
    |
    */
    'ignored_link_hosts' => [
        // Media sosial & tombol berbagi
        'facebook.com', 'fb.com', 'twitter.com', 'x.com', 'instagram.com',
        'youtube.com', 'youtu.be', 'linkedin.com', 'tiktok.com', 'telegram.me',
        't.me', 'whatsapp.com', 'wa.me', 'pinterest.com', 'threads.net',

        // Layanan Google & CDN/aset
        'google.com', 'google.co.id', 'goo.gl', 'gstatic.com', 'googleapis.com',
        'googletagmanager.com', 'google-analytics.com', 'doubleclick.net',
        'cloudflare.com', 'jsdelivr.net', 'unpkg.com', 'cdnjs.com', 'bootstrapcdn.com',
        'jquery.com', 'fontawesome.com', 'gravatar.com',

        // Platform & standar
        'wordpress.org', 'wordpress.com', 'wp.com', 'w3.org', 'schema.org',
        'mozilla.org', 'github.com', 'adobe.com', 'apple.com', 'microsoft.com',
        'whatsapp.net', 'openstreetmap.org', 'maps.app.goo.gl',
    ],

];
