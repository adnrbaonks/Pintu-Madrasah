# Changelog & Kebijakan Deprecation API — PINTU MADRASAH

Dokumen ini mencatat riwayat perubahan kontrak API v1 dan Webhook pada **PINTU MADRASAH** beserta kebijakan siklus hidup (*deprecation & sunset*).

---

## Kebijakan Siklus Hidup & Deprecation

1. **Stabilitas Kontrak v1:**
   - Perubahan di dalam mayor versi `v1` (`/api/v1/*`) wajib bersifat **aditif** (*additive-first*).
   - Penambahan properti baru pada objek JSON, penambahan query parameter opsional, atau penambahan jenis event webhook baru tidak dianggap sebagai *breaking change*.
2. **Pengumuman Deprecation:**
   - Endpoint atau atribut yang dijadwalkan untuk dipensiunkan akan diberi penanda middleware `api.deprecation` minimal **6 bulan** sebelum tanggal *Sunset*.
   - Header HTTP yang disertakan pada respons endpoint yang ter-deprecate:
     - `Deprecation: true`
     - `Sunset: <HTTP-date>`
     - `Link: </api/documentation>; rel="deprecation"`

---

## Riwayat Rilis

### v1.1.1 — 2026-11-24 (Hardening Audit WP0.5 Lanjutan)
#### Diperketat & Diperbaiki (Non-Breaking bagi Konsumen Sesuai Kontrak)
- **Allowlist Ketat pada Nested API Resource (`/api/v1/students`, `/api/v1/staff`, `/api/v1/classes`):**
  - Penambahan `SchoolClassResource`, `StudentCohortResource`, `StudentDocumentResource`, dan `PositionAssignmentResource`.
  - `ClassResource` pada `GET /api/v1/classes` kini menyaring relasi `homeroom_teacher` melalui `StaffResource` sehingga atribut PII wali kelas (`nik`, `nip`, `nuptk`, `npk`, `phone`, `email`, tempat/tanggal lahir, pendidikan, kepangkatan) tidak lagi bocor tanpa scope `staff:pii` dan parameter `?fields=`.
  - `StudentDocumentResource` menyembunyikan hasil OCR mentah (`ai_verification_result`) serta metadata internal storage (`gdrive_file_id`, `upload_retries`).
- **Idempotency-Key & Kanonikalisasi Payload:**
  - Middleware `IdempotencyKey` menggunakan atomic cache lock (`409 IDEMPOTENCY_KEY_IN_PROGRESS` bila request paralel masih berjalan) dan kanonikalisasi JSON rekursif untuk mendeteksi konflik payload (`422 IDEMPOTENCY_KEY_PAYLOAD_MISMATCH`) secara akurat tanpa bergantung pada urutan key JSON.
- **Transactional Outbox Lease Fencing & Integrasi Redis Queue Worker:**
  - Klaim `outbox_events` menggunakan `lease_token`, `reserved_at`, dan `lease_expires_at` sehingga eksekusi beririsan antara `DispatchWebhookJob` di antrean Redis dan `webhooks:process-outbox` tidak menghasilkan pengiriman ganda maupun *lost update*.

### v1.1.0 — 2026-11-23 (WP4, WP6, WP7)
#### Ditambahkan (Aditif & Non-Breaking)
- **Envelope `meta` Standar:** Seluruh endpoint `/api/v1/*` kini menyertakan objek `meta` (`request_id`, `generated_at`, serta `pagination` pada endpoint daftar) tanpa menghapus key paginator top-level Laravel (`current_page`, `data`, `total`, `per_page`).
- **Header `X-Request-Id` & `Idempotency-Key`:** Propagasi ID request pada setiap respons dan dukungan idempotensi 24 jam untuk operasi tulis.
- **Endpoint Baru:**
  - `GET /api/v1/students/by-id/{uuid}` untuk mengambil detail siswa berdasarkan UUID (mendukung siswa baru/PPDB yang belum memiliki NISN).
  - `GET /api/v1/health` untuk memeriksa status kesehatan koneksi database dan cache beserta `meta.request_id`.
- **Parameter Query Baru pada `/api/v1/students`, `/api/v1/staff`, `/api/v1/classes`:**
  - `?updated_since=<ISO-8601>` untuk sinkronisasi inkremental.
  - `?fields=id,name,...` untuk membatasi kolom yang dikembalikan sesuai allowlist.
  - `?include=...` untuk memuat relasi terizinkan (`currentCohort`, `cohorts`, `documents`, `positionAssignments`).
- **Keamanan API Key SHA-256 & Scope Granular:**
  - Format kunci `pm_live_<prefix8>_<secret40>` disimpan sebagai hash SHA-256 (`key_hash`) tanpa plaintext di database.
  - Penambahan scope `students:pii` dan `staff:pii`.
- **Proteksi Endpoint Integrasi EMIS:**
  - Middleware `integration.token:{purpose}` dengan token terkelola `pm_int_<prefix8>_<secret40>` dan pencatatan audit di `integration_logs`.
- **Transactional Outbox & Webhook v2:**
  - Katalog event GTK baru: `staff.created`, `staff.updated`, `staff.deactivated`, `staff.position.assigned`.
  - Header baru: `X-Webhook-Id` (`event_id`), `X-Webhook-Timestamp`, dan `X-Webhook-Signature-V2` (`v2=<hmac_sha256(timestamp.body, secret)>`). Header `X-Webhook-Signature` (v1) tetap dikirim untuk kompatibilitas mundur.
  - Penyensoran atribut PII (`nik`) secara default (`include_pii = false`) pada langganan webhook baru.

### v1.0.0 — 2026-10-03
- Rilis awal API Gateway `/api/v1/students`, `/api/v1/students/{nisn}`, `/api/v1/staff`, `/api/v1/classes`, `/api/v1/academic-years/active`, dan Webhook v1 untuk event siswa.
