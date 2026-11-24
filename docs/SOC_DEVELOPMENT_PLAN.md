# Rencana Pengembangan Monitoring dan SOC

Dokumen ini menjadi panduan belajar dan roadmap pengembangan project.
Fokusnya adalah membangun monitoring website yang aman sebagai platform SOC,
tanpa melakukan scanning terhadap aset yang belum memiliki izin eksplisit.

## 1. Pahami baseline project

Pelajari alur berikut:

- `app/Console/Commands/CheckWebsites.php`: menjadwalkan pengecekan website.
- `app/Jobs/CheckWebsiteJob.php`: menjalankan pengecekan sebagai queue job.
- `app/Services/Monitoring/WebsiteChecker.php`: HTTP uptime, response time, dan incident.
- `app/Services/Monitoring/DnsMonitor.php`: pemantauan A, AAAA, CNAME, NS, dan MX.
- `app/Services/Monitoring/CertificateInspector.php`: pemeriksaan sertifikat dan TLS.
- `app/Services/Monitoring/DomainExpiry.php`: masa berlaku domain melalui RDAP.
- `app/Services/Monitoring/ReputationChecker.php`: Google Safe Browsing.
- `app/Services/Monitoring/SecurityGrader.php`: HTTPS, HSTS, CSP, dan security headers.
- `routes/console.php`: jadwal monitoring dan pemeliharaan harian.

Tujuan tahap ini: mampu menjelaskan sumber data, frekuensi pengecekan,
status incident, dan kondisi yang memicu notifikasi.

## 2. Pelajari konsep SOC

Pelajari dan catat pemahaman mengenai:

- asset inventory;
- telemetry;
- detection dan alert;
- false positive;
- incident severity;
- evidence dan audit trail;
- triage;
- escalation;
- incident response;
- recovery;
- post-incident review.

Definisikan lifecycle incident:

```text
Open -> Triage -> Investigating -> Escalated -> Resolved / False Positive
```

## 3. Buat inventory dan scope aset

Sebelum memakai scanner atau OSINT aktif, buat daftar yang berisi:

- domain milik institusi;
- subdomain yang disetujui;
- alamat IP atau CIDR milik institusi;
- environment staging;
- pemilik aset;
- jadwal maintenance;
- kontak respons;
- metode approval scan;
- batas waktu dan frekuensi scan.

Tidak boleh melakukan scan atau OSINT aktif terhadap aset yang tidak tercantum
dan belum dikonfirmasi kepemilikannya.

Contoh scope:

```text
Target yang diizinkan:
- domain-go-id.example
- staging.domain-go-id.example

Target yang tidak diizinkan:
- domain lookalike milik pihak lain
- IP eksternal yang belum disetujui
- port scanning massal
- brute force atau exploitation
```

## 4. Tambahkan Nuclei secara aman

Urutan implementasi:

1. Gunakan satu domain staging milik sendiri.
2. Buat Artisan command baru, misalnya `websites:scan-nuclei`.
3. Baca target hanya dari inventory dan allowlist.
4. Jalankan template passive atau non-destructive.
5. Batasi target, severity, timeout, dan response size.
6. Jalankan dari queue dan scheduler mingguan.
7. Simpan hasil sebagai JSON yang memiliki target, template, severity,
   matched URL, evidence, dan timestamp.
8. Deduplicate finding sebelum membuat incident baru.
9. Tambahkan unit dan feature test.

Mulai dengan kategori berikut:

- exposed secret;
- exposed administrative panel;
- known exposure atau CVE;
- misconfiguration;
- missing security header;
- teknologi berisiko yang telah dikonfirmasi.

Jangan memulai dengan exploit mode, brute force, DAST intrusive,
atau template yang mengubah data.

Contoh verifikasi manual yang aman:

```bash
nuclei -u https://domain-staging.example \
  -severity low,medium,high,critical \
  -tags misconfig,exposure \
  -jsonl \
  -o nuclei-report.jsonl
```

## 5. Tambahkan brand protection

Buat service baru dengan pertanggungjawaban terpisah dari uptime monitoring.

Sumber yang direkomendasikan:

- Validin untuk DNS history dan certificate pivots;
- Phishing Catcher untuk certificate transparency;
- Google Safe Browsing untuk reputation;
- VirusTotal sebagai enrichment tambahan.

Data minimum untuk setiap finding:

- domain terdeteksi;
- domain resmi yang mirip;
- confidence atau skor kemiripan;
- timestamp;
- registrar dan DNS;
- certificate subject;
- evidence;
- status triage.

Status yang direkomendasikan:

```text
Investigating -> Confirmed / Benign -> Resolved
```

## 6. Normalisasi incident

Gunakan satu bentuk data incident lintas deteksi:

```php
[
    'source',
    'asset',
    'type',
    'severity',
    'title',
    'description',
    'evidence',
    'first_seen_at',
    'last_seen_at',
    'status',
    'confidence',
]
```

Tambahkan:

- assignee;
- runbook;
- closed reason;
- resolver;
- evidence hash;
- notification timestamp.

## 7. Tambahkan observability SOC

Data awal yang dikirim ke sistem log terpusat:

- Laravel application log;
- audit log;
- failed login;
- user ban;
- incident open dan resolved;
- website checks;
- DNS changes;
- certificate changes;
- domain expiry;
- queue failure;
- notification delivery.

Urutan stack:

1. Logstash untuk parsing.
2. Elasticsearch untuk penyimpanan.
3. Kibana untuk dashboard dan alert.
4. Wazuh setelah log dan asset inventory stabil.

Dashboard minimum:

- incident per severity;
- incident per asset;
- mean time to detect;
- mean time to respond;
- false positive berulang;
- duration outage;
- DNS changes;
- certificate expiry;
- security grade;
- queue failures.

## 8. Tambahkan DMARC

Gunakan parsedmarc apabila organisasi mengelola email pada domain yang dipantau.

Monitoring yang diperlukan:

- aggregate DMARC reports;
- source IP;
- SPF alignment;
- DKIM alignment;
- disposition;
- failed authentication trend;
- lookalike sending domains.

## 9. Buat detection rule

Contoh rule awal:

- domain owner atau nameserver berubah tanpa maintenance;
- MX berubah;
- HTTPS berubah menjadi HTTP;
- sertifikat berubah issuer atau nama;
- Google reputation berubah menjadi flagged;
- Nuclei menemukan exposure medium ke atas;
- incident berulang pada aset yang sama;
- failed login administrator berulang;
- role atau permission administrator berubah.

Setiap rule membutuhkan:

- author;
- description;
- data source;
- false-positive scenario;
- severity;
- tuning notes;
- test case.

## Urutan pengerjaan

```text
Scope dan inventory
-> Audit baseline project
-> Incident normalization
-> Nuclei read-only
-> Deduplication dan suppression
-> Brand protection
-> DMARC
-> Centralized logging
-> Wazuh
-> Detection engineering
-> Reporting dan post-incident review
```

## Kriteria selesai

Sebuah fitur dianggap selesai apabila:

- target berasal dari scope yang disetujui;
- tidak memperbesar risiko SSRF;
- memiliki timeout dan response size limit;
- menghasilkan evidence yang dapat diaudit;
- tidak mengirim alert berulang untuk finding yang sama;
- memiliki lifecycle resolved atau false positive;
- API key tidak muncul di log atau source code;
- memiliki unit dan feature test;
- dapat dijalankan sebagai queue/background job;
- memiliki runbook operasi dasar.
