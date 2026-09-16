<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Masa Simpan Log Audit
    |--------------------------------------------------------------------------
    |
    | Berapa hari catatan audit disimpan sebelum dipangkas oleh perintah
    | `audit:prune` yang berjalan harian (lihat routes/console.php).
    |
    | Pemangkasan sengaja hanya bisa lewat penjadwal, bukan dari halaman Log
    | Audit: jejak yang bisa dibersihkan oleh orang yang dicatatnya bukan jejak.
    | Setahun adalah bawaan yang aman untuk audit internal; naikkan bila ada
    | kewajiban retensi yang lebih panjang.
    |
    */
    'retention_days' => (int) env('AUDIT_RETENTION_DAYS', 365),

];
