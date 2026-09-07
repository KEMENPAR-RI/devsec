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

```bash
sudo cp deploy/security-worker.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now security-worker
sudo systemctl status security-worker
```

**supervisor** (cPanel/Plesk):

```bash
sudo cp deploy/security-worker.supervisor.conf /etc/supervisor/conf.d/security-worker.conf
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status security-worker:*
```

Sesuaikan `User`, `Group`, dan path direktori di file-nya dengan server.

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
php artisan queue:monitor default

# Harus menampilkan job yang diproses, bukan diam saja.
sudo journalctl -u security-worker -f      # systemd
tail -f storage/logs/worker.log             # supervisor
```

Di halaman Deteksi Judol, peringatan "pekerjaan menunggu di antrean" akan hilang
sendiri begitu antrean mulai dikonsumsi.

## Catatan konfigurasi

`DB_QUEUE_RETRY_AFTER` di `.env` **harus lebih besar** dari `$timeout` job
terlama (`ScanWebsiteForJudolJob` = 300 detik). Saat ini 320, dan itu benar.

Kalau nilainya lebih kecil, crawl yang berjalan lebih lama dari itu akan
dilepas dan dijalankan ulang **sementara yang pertama masih berjalan**, berulang
sampai kena batas percobaan dan gagal dengan `MaxAttemptsExceededException`.

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
