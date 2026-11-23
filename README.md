# PINTU MADRASAH — Pusat Informasi & Tata Usaha Madrasah Terpadu

**PINTU MADRASAH** adalah sistem informasi administrasi tata usaha dan *Master Data Hub* (Siswa, GTK, Organisasi, Akademik, PPDB, Mutasi, BK, Leger, dan Persuratan) berbasis **Laravel 12**, **Inertia.js**, **React 19 (TypeScript)**, dan **Tailwind CSS 4**.

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
   - State machine persetujuan 2 tingkat (**Verifikasi TU → Persetujuan Kepala Madrasah**) dengan pencegahan *self-approval*, penguncian baris (`lockForUpdate`), pemisahan field lokal vs `pending_external_sync` (EMIS), riwayat perubahan per-field, dan notifikasi in-app.
4. **RBAC Terpadu (`/pengaturan/roles`):**
   - Otorisasi berbasis `spatie/laravel-permission` dengan 7 role resmi madrasah (`admin`, `kepala_madrasah`, `kaur_tu`, `staf`, `operator`, `guru`, `pegawai`) dan 28 permission granular.
5. **Open API Gateway v1, Keamanan Key SHA-256 & Transactional Outbox Webhook (`/api-clients`, `/webhooks`):**
   - Endpoint `/api/v1/*` dengan kunci `pm_live_<prefix8>_<secret40>` ter-hash SHA-256, kontrol scope granular (termasuk `students:pii` & `staff:pii`), header `X-Request-Id`, `Idempotency-Key`, seleksi kolom `?fields=`, dan sinkronisasi inkremental `?updated_since=`.
   - Transactional Outbox (`outbox_events`) dengan pengiriman webhook ber-signature HMAC-SHA256 v2 (`X-Webhook-Signature-V2`), retry eksponensial 5x, deduplikasi `event_id`, dan replay dari UI.
6. **Sinkronisasi EMIS Siswa & EMIS GTK Terproteksi (`/emis/siswa`, `/emis/gtk`):**
   - Penarikan dan staging data EMIS menggunakan token kredensial integrasi terkelola (`pm_int_<prefix8>_<secret40>`), validasi masa berlaku JWT, dan audit `integration_logs`.
7. **Operasional Akademik & Kesiswaan:**
   - PPDB Online & Penempatan Kelas (`/ppdb`), Mutasi Masuk/Keluar (`/mutasi`), Kenaikan Kelas (`/kenaikan-kelas`), Bimbingan Konseling (`/bk`), Leger Nilai dengan Formula Dinamis per Tahun Ajaran (`/leger`), serta Cetak Dokumen & Verifikasi QR (`/verify/{token}`).

---

## Persyaratan Sistem

- **PHP:** 8.4+ (ekstensi: `pdo_mysql`, `pdo_sqlite`, `redis`, `gd`, `intl`, `zip`, `bcmath`)
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
| `php artisan webhooks:process-outbox` | Memproses antrean `outbox_events` yang berstatus `pending` (tambahkan `--retry-failed` untuk me-replay dead-letter) |
| `php artisan grades:recalculate` | Menghitung ulang nilai akhir & predikat pada leger berdasarkan formula tahun ajaran (`GradeService`) |
| `php artisan l5-swagger:generate` | Memperbarui berkas spesifikasi OpenAPI untuk `/api/documentation` |

---

## Dokumentasi Terkait

- [Standar API & Integrasi v1 (`docs/PINTU-STANDAR-API.md`)](docs/PINTU-STANDAR-API.md)
- [Changelog & Kebijakan Deprecation API (`docs/api/CHANGELOG.md`)](docs/api/CHANGELOG.md)
- [Panduan & Checklist Onboarding Aplikasi Satelit (`docs/api/SATELLITE-ONBOARDING.md`)](docs/api/SATELLITE-ONBOARDING.md)
- [Panduan Deployment & Rollback Staging/Produksi (`docs/DEPLOYMENT.md`)](docs/DEPLOYMENT.md)
