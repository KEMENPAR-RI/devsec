# Deploy pembaruan ke server

Untuk pembaruan yang **tidak** menambah migrasi atau dependency (mayoritas
kasus), urutannya:

- [ ] `.env` server wajib berisi `DB_QUEUE_RETRY_AFTER=320` (harus > `--timeout` terbesar, 300) dan `DB_HOST=127.0.0.1` (worker systemd memakai `PrivateTmp`, socket `/tmp/mysql.sock` tidak terlihat).

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

$PHP artisan queue:restart
ls -d storage bootstrap/cache        # wajib ada, kalau tidak worker gagal 226/NAMESPACE
sudo systemctl restart security-worker security-worker-scans
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

## Kenapa worker perlu di-restart

Worker adalah proses PHP yang berumur panjang: ia memuat kode aplikasi sekali
saat start dan menyimpannya di memori. Tanpa restart, worker terus menjalankan
**kode versi lama** sampai `--max-time=3600` mendaur-ulang prosesnya sendiri -
jadi selama sampai satu jam, web menampilkan versi baru sementara pengecekan
otomatis masih berjalan dengan versi lama.

```bash
sudo systemctl restart security-worker security-worker-scans          # systemd
sudo supervisorctl restart security-worker:* security-worker-scans:* # supervisor
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

## Memastikan deploy berhasil

```bash
sudo systemctl status security-worker security-worker-scans --no-pager  # keduanya active (running)
$PHP artisan queue:monitor database:checks-high,database:checks,database:default,database:scans  # harus mendekati 0
tail -20 storage/logs/laravel.log                   # tidak ada error baru
```

Lalu buka dashboard dan pastikan halaman Situs tampil normal.

## Kalau ada yang rusak setelah deploy

```bash
git log --oneline -5
git reset --hard <commit-sebelumnya>
npm ci && npm run build
$PHP artisan optimize:clear && $PHP artisan config:cache
sudo systemctl restart security-worker security-worker-scans
```


# Menjalankan worker antrean

## Kenapa ini perlu

Setiap pengecekan situs dan crawl judol otomatis dikirim sebagai *queued job*.
Tanpa worker yang mengambilnya, job hanya menumpuk di tabel `jobs` dan
**pemantauan otomatis tidak pernah jalan** - sementara "Cek Judol" dan "Cek URL
Tertentu" tetap berfungsi, karena keduanya jalan langsung di dalam request web.
Itu sebabnya gejalanya terbaca seperti "crawler rusak", padahal worker-nya yang
tidak ada.

## Langkah pemasangan

### 1. Bersihkan antrean yang menumpuk lebih dulu

Wajib dilakukan **sebelum** worker dinyalakan. Kalau tidak, worker akan langsung
memborong ribuan job basi sekaligus - menjalankan pengecekan berumur berhari-hari
terhadap semua situs serentak.

```bash
php artisan queue:flush-stale --failed
```

Tidak ada yang hilang: scheduler menjadwalkan ulang kedua job itu pada tick
berikutnya dengan data terbaru.

### 2. Pasang worker

Pilih **salah satu**, jangan keduanya - kalau dua-duanya aktif, setiap job jalan
dua kali.

**systemd** (VPS/server sendiri):

Ada dua worker, keduanya wajib dipasang:

- `security-worker` - antrean `checks-high,checks,default` (uptime + email notifikasi), `--timeout=150`
- `security-worker-scans` - antrean `scans` (crawl judol), `--timeout=300`

```bash
sudo cp deploy/security-worker.service deploy/security-worker-scans.service /etc/systemd/system/
ls -d /www/wwwroot/deadsec.apiprs.my.id/storage /www/wwwroot/deadsec.apiprs.my.id/bootstrap/cache  # wajib ada (ReadWritePaths)
sudo systemctl daemon-reload
sudo systemctl enable --now security-worker security-worker-scans
sudo systemctl status security-worker security-worker-scans
```

**supervisor** (plugin Supervisor aaPanel, cPanel/Plesk) - satu file berisi dua program:

```bash
sudo cp deploy/security-worker.supervisor.conf /etc/supervisor/conf.d/security-worker.conf
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status
```

systemd lebih disarankan: supervisor tidak bisa memakai hardening (`ProtectSystem=strict` dkk.) yang ada di file `.service`.

Di plugin Supervisor aaPanel: tambah dua daemon, salin `command`, `directory`,
dan `user` dari tiap `[program:...]` di file itu.

Sesuaikan `User`, `Group`, path PHP (`/www/server/php/84/bin/php`) dan path
direktori di file-nya dengan server. Kedua worker memakai `--memory=128` dan
`--max-jobs`/`--max-time` supaya proses didaur-ulang sebelum memori membengkak
(RAM server 1GB).

### 3. Hapus cron `--sync`

Kalau sebelumnya ada baris ini di crontab, **hapus**:

```
* * * * * php /var/www/security/artisan websites:scan-judol --sync
```

Dengan worker aktif, baris itu membuat setiap situs dipindai dua kali - sekali
oleh cron, sekali oleh worker.

### 4. Pastikan scheduler jalan

Ini satu-satunya baris cron yang dibutuhkan:

```
* * * * * cd /var/www/security && php artisan schedule:run >> /dev/null 2>&1
```

## Memastikan sudah benar

```bash
# Harus mendekati 0 dan tidak terus bertambah.
$PHP artisan queue:monitor database:checks-high,database:checks,database:default,database:scans

# Harus menampilkan job yang diproses, bukan diam saja.
sudo journalctl -u security-worker -u security-worker-scans -f   # systemd
tail -f storage/logs/worker.log storage/logs/worker-scans.log    # supervisor
```

Di halaman Deteksi Judol, peringatan "pekerjaan menunggu di antrean" akan hilang
sendiri begitu antrean mulai dikonsumsi.

## Catatan konfigurasi

`DB_QUEUE_RETRY_AFTER` di `.env` **harus lebih besar** dari `$timeout` job
terlama (`ScanWebsiteForJudolJob` = 300 detik). Saat ini 320, dan itu benar.

Kalau nilainya lebih kecil, crawl yang berjalan lebih lama dari itu akan
dilepas dan dijalankan ulang **sementara yang pertama masih berjalan**, berulang
sampai kena batas percobaan dan gagal dengan `MaxAttemptsExceededException`.

Env terkait antrean (lihat `.env.example`, default sudah aman):

- `MONITORING_QUEUE_CHECKS_HIGH`, `MONITORING_QUEUE_CHECKS`, `MONITORING_QUEUE_SCANS` - nama antrean; kalau diubah, `--queue=` di file worker harus ikut diubah
- `JUDOL_MAX_QUEUE` - batas job scan yang boleh menunggu di antrean `scans`
- `JUDOL_MAX_DISPATCH_PER_TICK` - jumlah scan yang di-dispatch per tick scheduler
- `MAIL_TIMEOUT` - timeout SMTP (detik) agar email lambat tidak menahan worker checks

## Website yang sedang mati tidak dipindai

Crawl judol hanya dijalankan untuk website yang statusnya `up` menurut
pengecekan uptime terakhir. Website berstatus `down` atau `unknown` dilewati,
dan `websites:scan-judol` menyebutkannya di output.

Ini disengaja. Pada host yang mati, setiap satu dari maksimal `judol.max_pages`
URL akan gagal dengan cara yang sama seperti yang baru saja dilaporkan oleh
pengecekan uptime - jadi job menghabiskan seluruh jatah 300 detiknya untuk
mengonfirmasi hal yang sudah diketahui, lalu gagal, lalu dijadwalkan ulang.
Gejalanya di systemd: `status=9/KILL` dan `restart counter` yang terus naik.

`--now` mengabaikan interval pemindaian, **bukan** status ini: website yang
tidak dapat diakses tetap tidak punya apa pun untuk di-crawl.

Kalau sebuah situs tampak tidak pernah dipindai, periksa statusnya lebih dulu:

```bash
php artisan websites:scan-judol
```

Output-nya menyebut nama setiap website yang dilewati beserta alasannya.

Situs internal (`is_internal_only`) memang selalu terbaca `down` dari luar, jadi
situs seperti itu tidak akan pernah masuk crawl otomatis. Tombol "Cek Judol" di
halaman detail tetap bisa dipakai untuk memindainya secara manual.

## Kalau worker gagal jalan

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
