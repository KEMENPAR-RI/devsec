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
sudo cp deploy/dashboard-worker.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now dashboard-worker
sudo systemctl status dashboard-worker
```

**supervisor** (cPanel/Plesk):

```bash
sudo cp deploy/dashboard-worker.supervisor.conf /etc/supervisor/conf.d/dashboard-worker.conf
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status dashboard-worker:*
```

Sesuaikan `User`, `Group`, dan path direktori di file-nya dengan server.

### 3. Hapus cron `--sync`

Kalau sebelumnya ada baris ini di crontab, **hapus**:

```
* * * * * php /var/www/dashboard/artisan websites:scan-judol --sync
```

Dengan worker aktif, baris itu membuat setiap situs dipindai dua kali - sekali
oleh cron, sekali oleh worker.

### 4. Pastikan scheduler jalan

Ini satu-satunya baris cron yang dibutuhkan:

```
* * * * * cd /var/www/dashboard && php artisan schedule:run >> /dev/null 2>&1
```

## Memastikan sudah benar

```bash
# Harus mendekati 0 dan tidak terus bertambah.
php artisan queue:monitor default

# Harus menampilkan job yang diproses, bukan diam saja.
sudo journalctl -u dashboard-worker -f      # systemd
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
