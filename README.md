# PINTU MADRASAH — Pusat Informasi & Tata Usaha Madrasah Terpadu

**PINTU MADRASAH** adalah sistem informasi administrasi tata usaha dan *Master Data Hub* (Siswa, GTK, Organisasi, Akademik, PPDB, Mutasi, BK, Leger, dan Persuratan) berbasis **Laravel 13 (`laravel/framework: ^13.17`)**, **Inertia.js**, **React 19 (TypeScript)**, dan **Tailwind CSS 4**.

Aplikasi ini menjadi sumber kebenaran tunggal (*Single Source of Truth*) bagi ekosistem aplikasi satelit madrasah (**SIPSMAD**, **PTSP**, **E-Surat**, **RDM**, dan **E-Learning**).

---

## Fitur & Modul Utama

1. **Master Data & Kamus Kepemilikan Data (`/siswa`, `/gtk`, `/master/*`, `/pengaturan/kamus-data`):**
   - Pengelolaan biodata Siswa & GTK dengan kebijakan otoritas per-field (`pintu` vs `emis`) yang ditegakkan oleh `DataOwnershipService`.
   - Master Mata Pelajaran (`/master/subjects`), Tingkatan Kelas (`/master/classes`), Tahun Ajaran & Kunci Periode (`/master/academic-years`), serta Rombongan Belajar (`/rombel`).
2. **Struktur Organisasi & Penugasan (`/organisasi*`):**
   - Hirarki Unit Kerja, Jabatan Struktural/Fungsional/Tambahan, SK Penugasan Pegawai dengan validasi anti-tumpang-tindih jabatan utama, dan resolusi otomatis Pejabat Penyetuju Level 1 (Kepala Madrasah) & Level 2 (Kepala TU / Waka).
3. **Layanan Mandiri GTK & Workflow Persetujuan Berjenjang (`/profil-gtk*`, `/verifikasi-gtk*`, `/persetujuan-gtk*`):**
   - Portal mandiri guru/pegawai untuk melihat profil, mengunggah dokumen kepegawaian privat, dan mengajukan perubahan data beserta bukti lampiran.
   - State machine persetujuan 2 tingkat (**Verifikasi TU (`verify_staff_changes`) → Persetujuan Kepala Madrasah (`approve_staff_changes`)** atau pejabat organisasi berwenang via `OrganizationApprovalService`) dengan pencegahan *self-approval*, penguncian baris (`lockForUpdate`), pemisahan field lokal vs `pending_external_sync` (EMIS), rekonsiliasi otomatis dengan deteksi konflik/timeout & dukungan pengosongan nilai (`null`) eksplisit pada field EMIS opsional, riwayat perubahan per-field, serta notifikasi in-app.
4. **RBAC Terpadu & Otorisasi Dokumen Object-Level (`/pengaturan/roles`, `/arsip-digital`, `/archives/{type}/{filename}`):**
   - Otorisasi berbasis `spatie/laravel-permission` sebagai sumber kebenaran tunggal dengan 7 role resmi madrasah (`admin`, `kepala_madrasah`, `kaur_tu`, `staf`, `operator`, `guru`, `pegawai`) dan **50 permission granular** yang dapat dicabut (*revoke*) secara *real-time*.
   - Pemisahan kewenangan per jenis dokumen privat (`view_student_documents`, `view_ppdb_documents`, `view_mutation_documents`, `view_staff`, serta kepemilikan objek/`verify_staff_changes`/`approve_staff_changes` untuk `StaffChangeAttachment`), pembatasan `access_logs` arsip digital dengan `view_audit_logs`, pembatasan perubahan klasifikasi/visibilitas arsip bagi pemilik non-manajer (`manage_archives`), pemisahan permission tulis pada `documents/{type}/{id}/retry`, serta penyaringan metadata arsip digital (`ArchiveService::canView()` & `ArchiveService::recentViewable()`) di seluruh modul (Pengumuman, Program Kerja, PKKM, Rapat).
5. **Open API Gateway v1, Keamanan Key SHA-256 & Transactional Outbox Webhook (`/api-clients`, `/webhooks`):**
   - Endpoint `/api/v1/*` dengan kunci per-klien `pm_live_<prefix8>_<secret40>` ter-hash SHA-256 (fallback global API key telah dipensiunkan), kontrol scope granular (termasuk `students:identifiers`, `students:pii`, dan `staff:pii`), allowlist eksplisit pada Resource utama maupun relasi bertingkat (`SchoolClassResource`, `StudentCohortResource`, `StudentDocumentResource`, `PositionAssignmentResource`), header `X-Request-Id`, `Idempotency-Key` (kanonikalisasi JSON rekursif + atomic lock), seleksi kolom `?fields=`, dan sinkronisasi inkremental `?updated_since=`.
   - Transactional Outbox (`outbox_events`) dengan *lease fencing* (`lease_token`, `reserved_at`, `lease_expires_at`), enkripsi secret langganan webhook (`WebhookSubscription`), proteksi SSRF outbound (`WebhookUrlGuard` dengan blokir IP privat/loopback/metadata, anti-DNS rebinding `CURLOPT_RESOLVE`, dan larangan redirect 3xx), sanitasi PII rekursif berbasis scope klien, pengiriman webhook ber-signature HMAC-SHA256 v2 (`X-Webhook-Signature-V2`), retry eksponensial dengan batas `max_attempts` yang dapat di-retry saat dead-letter (`webhooks:process-outbox --retry-failed`), deduplikasi `event_id`, dan replay dari UI.
6. **Sinkronisasi EMIS Siswa & EMIS GTK Terproteksi (`/emis/siswa`, `/emis/gtk`):**
   - Penarikan dan staging data EMIS menggunakan token kredensial integrasi terkelola (`pm_int_<prefix8>_<secret40>`), validasi masa berlaku JWT, pembedaan field staging yang tidak dikirim vs sengaja dikosongkan (`null`), dan audit `integration_logs`.
7. **Tata Kelola Madrasah, Operasional Akademik & Kesiswaan:**
   - Arsip Digital Berversi (`/arsip-digital`), Rapat & Notulen (`/rapat-dinas`, termasuk kunci notulen yang sudah disahkan dan jejak audit revisi `POST /rapat-dinas/{meeting}/minutes/revise`), Program Kerja & Realisasi (`/program-kerja`), Instrumen Penilaian PKKM (`/pkkm`, dengan validasi kelengkapan skor verifikasi & bukti terverifikasi sebelum finalisasi), Pengumuman Internal (`/pengumuman`), dan Kalender Akademik (`/kalender`).
   - PPDB Online & Penempatan Kelas (`/ppdb`), Mutasi Masuk/Keluar (`/mutasi`), Kenaikan Kelas (`/kenaikan-kelas`), Bimbingan Konseling (`/bk`), Leger Nilai dengan Formula Dinamis per Tahun Ajaran (`/leger`), serta Cetak Dokumen & Verifikasi QR (`/verify/{token}`).

---

## Persyaratan Sistem

- **PHP:** 8.3+ (disarankan 8.4+; ekstensi: `pdo_mysql`, `pdo_sqlite`, `redis`, `gd`, `intl`, `zip`, `bcmath`)
- **Database:** MySQL 8.0+ (Produksi/Staging) atau SQLite (Pengembangan Lokal)
- **Cache & Queue:** Redis 7+ atau Database Queue
- **Node.js:** 22+ & NPM

---

## Instalasi & Pengembangan Lokal

```bash
# 1. Pasang dependensi PHP & JS
composer install
npm install

# 2. Salin konfigurasi environment & buat APP_KEY
cp .env.example .env
php artisan key:generate

# 3. Jalankan migrasi & seeder awal (termasuk RBAC RolePermissionSeeder)
php artisan migrate --seed

# 4. Bangun aset frontend (atau jalankan vite dev server)
npm run build

# 5. Jalankan server aplikasi & worker antrean
php artisan serve
php artisan queue:work --queue=default,webhooks,emis
```

---

## Pengujian & Kualitas Kode

Seluruh perubahan wajib lulus test otomatis pada **SQLite** dan **MySQL 8**, pemeriksaan format **Laravel Pint**, validasi **OpenAPI**, serta build **Vite TypeScript**:

```bash
# Jalankan seluruh test suite (SQLite in-memory)
php artisan test

# Jalankan seluruh test suite terhadap MySQL 8
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=pintu_test \
DB_USERNAME=root DB_PASSWORD=root php artisan test

# Jalankan integration test antrean Redis nyata (Transactional Outbox & Worker)
QUEUE_CONNECTION=redis REDIS_CLIENT=phpredis REDIS_HOST=127.0.0.1 REDIS_PORT=6379 \
php artisan test tests/Feature/RedisQueueOutboxIntegrationTest.php

# Format kode PHP dengan Laravel Pint
./vendor/bin/pint

# Generate ulang spesifikasi OpenAPI (Swagger)
php artisan l5-swagger:generate

# Build produksi frontend
npm run build
```

---

## Perintah Artisan Penting

| Perintah | Fungsi |
|---|---|
| `php artisan api-keys:hash-existing` | Mem-backfill `key_hash` SHA-256 dari `api_key` plaintext lama lalu mengosongkan plaintext |
| `php artisan settings:encrypt-secrets` | Mengenkripsi nilai `settings` sensitif dan secret `webhook_subscriptions` plaintext secara idempoten |
| `php artisan webhooks:process-outbox` | Memproses antrean `outbox_events` yang berstatus `pending` (tambahkan `--retry-failed` untuk me-replay dead-letter dengan menaikkan `max_attempts`) |
| `php artisan grades:recalculate` | Menghitung ulang nilai akhir & predikat pada leger berdasarkan formula tahun ajaran (`GradeService`) |
| `php artisan l5-swagger:generate` | Memperbarui berkas spesifikasi OpenAPI untuk `/api/documentation` |

---

## Dokumentasi Terkait

- [Standar API & Integrasi v1 (`docs/PINTU-STANDAR-API.md`)](docs/PINTU-STANDAR-API.md)
- [Changelog & Kebijakan Deprecation API (`docs/api/CHANGELOG.md`)](docs/api/CHANGELOG.md)
- [Panduan & Checklist Onboarding Aplikasi Satelit (`docs/api/SATELLITE-ONBOARDING.md`)](docs/api/SATELLITE-ONBOARDING.md)
- [Panduan Deployment & Rollback Staging/Produksi (`docs/DEPLOYMENT.md`)](docs/DEPLOYMENT.md)
