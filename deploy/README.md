# Deploy pembaruan ke server

Untuk pembaruan yang **tidak** menambah migrasi atau dependency (mayoritas
kasus), urutannya:

- [ ] `.env` server wajib berisi `DB_QUEUE_RETRY_AFTER=320` (harus > `--timeout` terbesar, 300) dan `DB_HOST=127.0.0.1` (worker dijalankan scheduler sebagai user cron; `127.0.0.1` menghindari masalah socket `/tmp/mysql.sock`, terutama kalau memakai worker systemd).

```bash
cd /www/wwwroot/deadsec.apiprs.my.id
PHP=/www/server/php/84/bin/php           # sesuaikan versi: ls /www/server/php/

$PHP artisan down                     # opsional, aman dilewati untuk perubahan kecil
git pull origin main

npm ci && npm run build              # WAJIB kalau ada blade yang berubah - lihat catatan
$PHP artisan migrate --force         # aman dijalankan meski tidak ada migrasi baru

$PHP artisan optimize:clear
$PHP artisan config:cache
$PHP artisan route:cache
$PHP artisan view:cache

$PHP artisan queue:restart           # hanya berpengaruh bila memakai worker systemd/supervisor
$PHP artisan up
```

## Kenapa `npm run build` wajib walau tidak ada file CSS yang diubah

`public/build` tidak ikut di-commit (lihat `.gitignore`), jadi CSS di server
adalah hasil build lama. Tailwind hanya memasukkan kelas yang **ditemukannya di
dalam blade saat build dijalankan** - kelas yang baru dipakai di blade tidak ada
di CSS lama, dan elemennya muncul tanpa gaya sama sekali.

Gejalanya menyesatkan: tidak ada error apa pun, halaman tetap terbuka, hanya saja
elemen barunya tidak berwarna. Titik status lonjakan (kuning/oranye) adalah
contoh persisnya - tanpa rebuild keduanya jadi transparan dan terbaca seolah
tidak ada status apa-apa.

Cara memastikan sebuah kelas sudah ada di CSS terbangun:

```bash
grep -o 'bg-orange-500' public/build/assets/*.css | head -1
```

## Kenapa worker tidak perlu di-restart

Worker dijalankan scheduler (lihat bagian di bawah): tiap menit ia start baru,
menghabiskan antrean, lalu berhenti. Jadi kode terbaru langsung dipakai pada
menit berikutnya tanpa langkah restart.

Kalau server masih memakai worker systemd/supervisor (opsional, lihat bawah),
worker itu berumur panjang dan **wajib** di-restart setiap deploy:

```bash
sudo systemctl restart security-worker          # systemd
sudo supervisorctl restart security-worker:*    # supervisor
```

## Kalau pembaruan menambah migrasi

Cek dulu apa yang akan dijalankan sebelum menjalankannya:

```bash
php artisan migrate:status
php artisan migrate --force --pretend       # tampilkan SQL-nya, tanpa mengeksekusi
```

Backup dulu kalau migrasinya mengubah atau menghapus kolom:

```bash
php artisan backup:database
```

### Rilis penghapusan modul Offensive (Judol, Defacement, Perubahan Konten)

Migrasi `2026_11_05_100001_remove_offensive_module` **menghapus permanen** tabel
`judol_*`, `defacement_findings`, `page_snapshots`, `page_changes`,
`page_change_forensics`, kolom `judol_*` di `websites`, insiden bertipe judol /
defacement, antrean `scans`, serta berkas `judol-evidence/` dan `page-bodies/`.
Data uptime, insiden lain, pengguna, role, dan pengaturan tidak disentuh.
API key AI dipindah otomatis ke nama baru (`ai_api_key`), jadi fitur "Selidiki"
insiden tetap jalan.

Urutan aman:

1. `php artisan backup:database` (data modul tidak bisa dipulihkan tanpa backup).
2. Deploy kode, lalu `php artisan migrate --force`.
3. Matikan worker lama `security-worker-scans` bila dipakai (lihat bagian worker di bawah).

## Memastikan deploy berhasil

```bash
sudo systemctl status security-worker --no-pager  # active (running), bila memakai worker systemd
$PHP artisan queue:monitor database:checks-high,database:checks,database:default,database:checks-low  # harus mendekati 0
tail -20 storage/logs/laravel.log                   # tidak ada error baru
```

Lalu buka dashboard dan pastikan halaman Situs tampil normal.

## Kalau ada yang rusak setelah deploy

```bash
git log --oneline -5
git reset --hard <commit-sebelumnya>
npm ci && npm run build
$PHP artisan optimize:clear && $PHP artisan config:cache
sudo systemctl restart security-worker
```


# Menjalankan worker antrean

## Kenapa ini perlu

Setiap pengecekan situs otomatis dikirim sebagai *queued job*. Tanpa worker
yang mengambilnya, job hanya menumpuk di tabel `jobs` dan **pemantauan otomatis
tidak pernah jalan**.

## Cara default: worker dijalankan scheduler (hanya perlu 1 cron)

`routes/console.php` mendaftarkan satu `queue:work --sleep=3` (tanpa `--stop-when-empty`) yang
dipicu tiap menit oleh scheduler, jadi **tidak perlu systemd/supervisor**:

- antrean `checks-high,checks,default,checks-low` (uptime + email notifikasi; `checks-low` = re-cek situs Warning), `--max-time=55`, `--sleep=3`, `--timeout=150`

Worker ini `withoutOverlapping` (maksimal satu proses) dan `runInBackground`
(pengecekan yang lama tidak menahan tugas terjadwal lain).
Worker tetap hidup ~55 detik per tick dan polling tiap 3 detik, jadi job (termasuk retry tertunda) diambil dalam hitungan detik. Ada juga `websites:check --warning` tiap 15 detik, sehingga `schedule:run` tetap hidup ~60 detik tiap menit (normal).

### 1. Bersihkan antrean yang menumpuk lebih dulu

Lakukan **sebelum** cron dipasang bila antrean pernah menumpuk. Kalau tidak,
worker akan langsung memborong ribuan job basi sekaligus - menjalankan pengecekan
berumur berhari-hari terhadap semua situs serentak.

```bash
php artisan queue:flush-stale --failed
```

Tidak ada yang hilang: scheduler menjadwalkan ulang pengecekan pada tick
berikutnya dengan data terbaru.

### 2. Pasang scheduler

Ini satu-satunya baris cron yang dibutuhkan. Jalankan sebagai user pemilik
direktori aplikasi (mis. `www`), karena user itu juga yang menjalankan worker:

```
* * * * * cd /www/wwwroot/deadsec.apiprs.my.id && /www/server/php/84/bin/php artisan schedule:run >> /dev/null 2>&1
```

### 3. Matikan worker lama (jika ada) dan hapus cron `--sync`

Kalau sebelumnya memasang worker systemd/supervisor, matikan supaya tidak jalan
bersamaan dengan worker scheduler:

```bash
sudo systemctl disable --now security-worker security-worker-scans   # systemd (security-worker-scans = worker lama untuk modul offensive, sudah dihapus)
sudo supervisorctl stop security-worker:* security-worker-scans:*    # supervisor (lalu hapus programnya)
```

Hapus juga entri cron lama `websites:scan-judol` / `websites:scan-defacement`
bila ada - perintahnya sudah tidak ada.

## Alternatif: worker berumur panjang (systemd/supervisor)

Hanya bila butuh latensi nol atau hardening systemd. **Hapus dulu** entri
`queue:work` di `routes/console.php`, lalu pasang **salah satu** (jangan keduanya):

**systemd** (VPS/server sendiri):

- `security-worker` - antrean `checks-high,checks,default,checks-low`, `--timeout=150`

```bash
sudo cp deploy/security-worker.service /etc/systemd/system/
ls -d /www/wwwroot/deadsec.apiprs.my.id/storage /www/wwwroot/deadsec.apiprs.my.id/bootstrap/cache  # wajib ada (ReadWritePaths)
sudo systemctl daemon-reload
sudo systemctl enable --now security-worker
sudo systemctl status security-worker
```

**supervisor** (plugin Supervisor aaPanel, cPanel/Plesk):

```bash
sudo cp deploy/security-worker.supervisor.conf /etc/supervisor/conf.d/security-worker.conf
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status
```

Di plugin Supervisor aaPanel: tambah satu daemon, salin `command`, `directory`,
dan `user` dari `[program:...]` di file itu. Sesuaikan `User`, `Group`,
path PHP dan path direktori dengan server. Worker memakai `--memory=128`
dan `--max-jobs`/`--max-time` supaya proses didaur-ulang sebelum memori
membengkak (RAM server 1GB). Scheduler tetap wajib (1 baris cron di atas).

## Memastikan sudah benar

```bash
# Harus mendekati 0 dan tidak terus bertambah.
$PHP artisan queue:monitor database:checks-high,database:checks,database:default,database:checks-low

# Pastikan worker terdaftar di scheduler.
$PHP artisan schedule:list | grep queue:work

# Worker scheduler tidak punya log sendiri; job gagal tercatat di sini.
$PHP artisan queue:failed
tail -20 storage/logs/laravel.log

# Hanya bila memakai worker berumur panjang:
sudo journalctl -u security-worker -f   # systemd
tail -f storage/logs/worker.log         # supervisor
```

## Catatan konfigurasi

`DB_QUEUE_RETRY_AFTER` di `.env` **harus lebih besar** dari `--timeout` worker
(150 detik) dan `$timeout` job terlama. Saat ini 320, dan itu benar.

Kalau nilainya lebih kecil, job yang berjalan lebih lama dari itu akan dilepas
dan dijalankan ulang **sementara yang pertama masih berjalan**, berulang sampai
kena batas percobaan dan gagal dengan `MaxAttemptsExceededException`.

Env terkait antrean (lihat `.env.example`, default sudah aman):

- `MONITORING_QUEUE_CHECKS_HIGH`, `MONITORING_QUEUE_CHECKS`, `MONITORING_QUEUE_CHECKS_LOW` - nama antrean; worker scheduler otomatis mengikuti. Hanya file systemd/supervisor yang `--queue=`-nya harus diubah manual
- `AI_TIMEOUT`, `AI_MAX_RETRIES`, `AI_MAX_RETRY_DELAY` - batas waktu/retry panggilan AI (analisis insiden)
- `MAIL_TIMEOUT` - timeout SMTP (detik) agar email lambat tidak menahan worker checks

## Kalau worker systemd/supervisor gagal jalan

Bagian ini hanya untuk alternatif worker berumur panjang.

### `status=200/CHDIR` - "Changing to the requested working directory failed"

`WorkingDirectory` di file service menunjuk ke direktori yang tidak ada, jadi
systemd menyerah **sebelum** PHP sempat dijalankan. Ini kegagalan paling umum
saat pertama memasang, karena path di file contoh harus disesuaikan dulu.

Cocokkan dengan lokasi aplikasi yang sebenarnya:

```bash
# Path yang benar adalah direktori yang berisi file artisan.
cd /www/wwwroot/deadsec.apiprs.my.id && pwd && ls artisan

# Perbaiki, lalu muat ulang.
sudo nano /etc/systemd/system/security-worker.service
sudo systemctl daemon-reload
sudo systemctl restart security-worker
sudo systemctl status security-worker
```

### `status=203/EXEC` - PHP tidak ditemukan di path itu

`ExecStart` memakai `/usr/bin/php`. Kalau PHP di server ada di tempat lain
(umum pada aaPanel/cPanel dengan PHP versi tersendiri), cari lokasinya dan
tulis path absolutnya:

```bash
which php
# mis. /www/server/php/82/bin/php
```

### Worker jalan tapi antrean tidak berkurang

Pastikan worker memakai `.env` yang sama dengan aplikasi web - terutama
`QUEUE_CONNECTION` dan kredensial database. `WorkingDirectory` yang salah bisa
membuat worker membaca `.env` yang berbeda tanpa error apa pun.

```bash
sudo systemctl show security-worker -p WorkingDirectory
php artisan queue:monitor default
```

### Izin file

Jalankan worker sebagai user yang sama dengan pemilik direktori aplikasi.
Kalau `User=www-data` sedangkan file dimiliki user lain, worker tidak bisa
menulis ke `storage/logs` dan gagal saat mencatat log.

```bash
stat -c '%U:%G' /www/wwwroot/deadsec.apiprs.my.id
```
