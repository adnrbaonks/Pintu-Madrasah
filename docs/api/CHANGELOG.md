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

### v1.2.1 — 2026-11-27 (WP0.5.10 — Data Ownership Enforcement)
#### Diperketat & Diperbaiki
- **Kunci Mutasi EMIS di Mode Produksi & Prosedur Break-Glass (`P1.1`):**
  - Penambahan `lifecycle_mode` (`development` | `migration` | `production`, default `production` via `PINTU_LIFECYCLE_MODE`) dan konfigurasi `break_glass` di `config/data_ownership.php`.
  - Pada mode `production`, endpoint `POST /api/emis/commit`, `POST /api/emis-gtk/commit`, `CommitEmisStagingJob`, `CommitEmisGtkStagingJob`, dan `StudentController::commitEmisDetail()` menolak pembuatan record baru, *smart-fill* kolom kosong, maupun overwrite master data PINTU (`403 EMIS_COMMIT_DISABLED_IN_PRODUCTION`).
  - Token integrasi (`X-Integration-Token` / Bearer `pm_int_...`) dilarang memicu endpoint commit (`403 INTEGRATION_TOKEN_COMMIT_FORBIDDEN`).
  - Prosedur darurat *Break-Glass Migration* di produksi hanya aktif bila `PINTU_EMIS_BREAK_GLASS_ENABLED=true`, dieksekusi oleh pengguna manusia ber-permission `sync_emis` + `break_glass_emis_import`, serta menyertakan `break_glass=true`, `break_glass_confirmed=true`, dan `break_glass_reason` >= 15 karakter dengan pencatatan audit `emis_break_glass_commit_authorized`.
- **Penutupan Bypass Edit Langsung pada Master GTK (`P1.2`):**
  - `DataOwnershipService::isEditable()` dan `getEditableFields()` kini memeriksa `change_policy === 'direct'` **dan** `approval_required === false`.
  - Seluruh atribut identitas, biodata, kepangkatan, pendidikan, dan kepegawaian GTK di `config/data_ownership.php` memiliki `change_policy = approval_required` dan `approval_required = true`, sehingga `PUT /gtk/{staff}` (`StaffController::update()`) tidak dapat digunakan untuk mem-bypass alur persetujuan 2 tingkat (GTK → Kaur TU → Kepala Madrasah). Hanya `is_active` yang tetap `direct`.
- **Isolasi Domain EMIS Siswa vs GTK, Otorisasi Update Task, & Minimisasi PII (`P1.3`, `P2.1`):**
  - `/api/emis/*` dikunci hanya untuk `entity_type = student` (`emis_siswa`) dan `/api/emis-gtk/*` dikunci hanya untuk `entity_type = staff` (`emis_gtk`); akses lintas domain ditolak dengan `403 FORBIDDEN_ENTITY_SCOPE`.
  - `PATCH /api/emis*/update-tasks/{task}` menolak token integrasi (`403 INTEGRATION_TOKEN_TASK_UPDATE_FORBIDDEN`), mewajibkan sesi pengguna manusia dengan permission `sync_emis`, dan mengunci task `completed` dari pembukaan ulang tanpa `reopen_confirmed=true` + `operator_notes` >= 10 karakter (`422 INVALID_TASK_STATUS_TRANSITION`).
  - Snapshot `pintu_snapshot` dan `emis_snapshot` pada `GET /reconciliation-batches` menyamarkan (*masking*) atribut PII sensitif.
- **Peningkatan Kualitas Rekonsiliasi, Verifikasi Dokumen Siswa, & Pelestarian Riwayat (`P2.2`–`P2.7`):**
  - Prioritas pencocokan siswa `emis_student_id -> nisn -> nik` dengan deteksi konflik multi-kandidat (`needs_review`), indeks hash $O(1)$ per chunk, semantik `diff_type` eksplisit (`emis_missing_value`, `pintu_missing_value`, `value_mismatch`), kewajiban `StudentDocument` berstatus `verified` saat mengubah identitas siswa, jejak audit `student_master_updated`, serta pelestarian baris `EmisStaging` (`status = committed`) dan snapshot rekonsiliasi.

### v1.2.0 — 2026-11-27 (Revisi Data Ownership SSOT & Final Security/Integrity Hardening)
#### Ditambahkan & Diperketat
- **Revisi Data Ownership — PINTU MADRASAH sebagai Production SSOT (`config/data_ownership.php`, `DataOwnershipService`):**
  - Seluruh atribut master Siswa (`student`) dan GTK (`staff`) kini dimiliki oleh PINTU MADRASAH (`owner_system = local`, `production_sync_direction = outbound`), sedangkan ID teknis eksternal (`emis_student_id`, `emis_gtk_id`) dipertahankan sebagai referensi integrasi (`owner_system = emis`, `change_policy = read_only`).
  - Perubahan identitas penting Siswa oleh operator (`edit_students`) mewajibkan dokumen pendukung (`StudentDocument`), alasan perubahan pada data terkunci/terverifikasi (`identity_change_reason`), jejak audit `ActivityLog`, serta membuat tugas pembaruan outbound ke EMIS (`EmisUpdateTask`).
  - Perubahan data GTK yang disetujui melalui alur 2 tingkat (Verifikasi Kaur TU → Persetujuan Kepala Madrasah) langsung diterapkan ke Master GTK PINTU dan membuat tugas pembaruan outbound ke EMIS (`EmisUpdateTask`).
- **Pemisahan Endpoint Import Awal, Rekonsiliasi Non-Destruktif & Antrean Pembaruan EMIS (`/api/emis/*`, `/api/emis-gtk/*`):**
  - Import awal (`POST /api/emis/commit`, `POST /api/emis-gtk/commit`) mencatat metadata `emis_import_batches` dan menolak overwrite otomatis terhadap master PINTU kecuali dikonfirmasi eksplisit (`allow_overwrite=true` & `overwrite_confirmed=true`).
  - Endpoint baru `POST /api/emis/reconcile`, `POST /api/emis-gtk/reconcile`, dan `GET /api/emis*/reconciliation-batches` untuk rekonsiliasi non-destruktif (`same`, `different`, `pintu_only`, `emis_only`, `needs_review`).
  - Endpoint baru `GET /api/emis*/update-tasks` dan `PATCH /api/emis*/update-tasks/{task}` untuk pelacakan pembaruan outbound ke EMIS oleh operator.
- **Penutupan Bypass PII Webhook Tanpa API Client (`P1`):**
  - Pembuatan langganan webhook dengan `include_pii = true` wajib menautkan `api_client_id` aktif (tidak dicabut dan belum kedaluwarsa) yang memiliki scope `students:pii` (untuk event `student.*`) atau `staff:pii` (untuk event `staff.*`).
  - Pada saat runtime (`WebhookPublisher::canIncludePii()`), langganan tanpa `api_client_id` selalu disanitasi secara *fail-closed* (`false`).
- **Verifikasi Transport Runtime SSRF (`P2.5`):**
  - `WebhookUrlGuard::buildPinnedTransportOptions()` memverifikasi ketersediaan ekstensi cURL dan `CURLOPT_RESOLVE` secara *fail-closed*, menonaktifkan proxy environment (`proxy => ''`, `CURLOPT_PROXY => ''`, `CURLOPT_NOPROXY => '*'`), menonaktifkan redirect, dan mengunci protokol serta IP publik tervalidasi.

### v1.1.2 — 2026-11-25 (Hardening Audit WP1–WP17)
#### Diperketat & Diperbaiki
- **Scope `students:identifiers` & Pensiun Fallback Global API Key (`WP15`, `WP16`):**
  - Penambahan scope `students:identifiers` untuk mengontrol eksposur `nisn` pada `StudentResource` serta akses endpoint `GET /api/v1/students/{nisn}` (migrasi otomatis menambahkan `students:identifiers` pada klien lama pemegang `students:read` untuk kelangsungan operasional).
  - Fallback global API key (`Setting::get('api_key')` / `API_GATEWAY_KEY`) pada middleware `CheckApiKey` telah dipensiunkan; seluruh request `/api/v1/*` wajib menggunakan kunci per-klien `ApiClient`.
- **Sanitasi PII Webhook Rekursif, Enkripsi Secret & Proteksi SSRF Outbound (`WP1`, `WP7`, `WP8`):**
  - `WebhookPublisher` menyensor PII dan identifier (`nik`, `nisn`, `nip`, `nuptk`, `npk`, `kk_number`, `family_card_number`, kontak, tempat/tanggal lahir, penghasilan) secara rekursif kecuali `include_pii = true` dan `ApiClient` terkait memiliki scope `students:pii` / `staff:pii`.
  - `WebhookSubscription.secret` dienkripsi at-rest dan disembunyikan dari serialisasi model (`$hidden`).
  - `WebhookUrlGuard` memblokir target SSRF (localhost, IP privat/loopback/link-local/CGNAT, cloud metadata, DNS rebinding via `CURLOPT_RESOLVE`, dan redirect HTTP 3xx).
- **Pemulihan Dead-Letter Outbox (`WP9`, `WP14`):**
  - Penambahan kolom `max_attempts` pada `outbox_events` sehingga `php artisan webhooks:process-outbox --retry-failed` dan replay admin dapat memproses ulang event `failed` tanpa kehilangan riwayat `attempts`.

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

## WP0.5.11
- `GET /api/emis{,-gtk}/reconciliation-batches` kini terpaginasi (`page`, `per_page` maks 50) dan **tidak lagi memuat `items`**; gunakan `GET .../reconciliation-batches/{batch}/items` (`per_page` maks 200, filter `classification`).
- `GET .../update-tasks` terpaginasi (`per_page` maks 100); respons memuat `meta`.
- PII pada snapshot, `differences`, `changed_fields`, `match_value`, dan staging list disanitasi rekursif berdasarkan data ownership dictionary. Token integrasi: seluruh PII (termasuk nama/tgl lahir) disamarkan; operator sesi: identifier dimasking, nama/tgl lahir tetap terlihat.
