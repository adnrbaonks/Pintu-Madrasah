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
- `GET .../reconciliation-batches` kini menyertakan `items_count` per batch (tanpa memuat item) agar konsumen bisa memutuskan kapan memanggil endpoint items.

## WP0.5.12

- `POST /api/emis/reconcile` dan `POST /api/emis-gtk/reconcile` kini mengembalikan ringkasan batch (`batch`, `data`) tanpa relasi `items`; field `items_count` ditambahkan. Item diambil lewat `GET .../reconciliation-batches/{batch}/items` (tersanitasi, terpaginasi).
- `GET /api/emis/staging` dan `GET /api/emis-gtk/staging` terpaginasi (`per_page` default 50, maks 200, `page`); `data` tetap array dan ditambah `meta` (`current_page`, `per_page`, `total`, `last_page`). PII disanitasi sesuai level (token: seluruh PII dimasking; sesi: identifier dimasking parsial).
- Permission baru `reconcile_emis` (operator, admin). Matriks: lihat = `view_emis`; rekonsiliasi = `reconcile_emis`; pull/cancel/commit/import/ubah update-task = `sync_emis`. Token integrasi tetap dibatasi purpose (`emis_siswa` vs `emis_gtk`) dan tidak dapat commit/ubah task.
- Fallback sesi tidak lagi meloloskan role (`staf`, `kaur_tu`, dll.) tanpa permission eksplisit.

## WP0.5.13 — EMIS token scope, matching integrity, scale

- **Ability token integrasi** (kolom `integration_tokens.abilities`): `staging:read`, `staging:write`, `reconcile:read`, `reconcile:run`, `sync:read`, `sync:trigger`, `sync:cancel`, `tasks:read`, `credentials:update`. Pelanggaran → `403 FORBIDDEN_TOKEN_ABILITY`.
- **Migrasi token lama**: `abilities = NULL` diperlakukan *read-only* (`staging:read`, `reconcile:read`, `sync:read`, `tasks:read`). Token yang membutuhkan import/reconcile/pull/cancel/kredensial harus diterbitkan ulang dengan ability eksplisit (`abilities[]` pada pembuatan token).
- Commit staging dan PATCH update-task tetap tidak pernah boleh lewat token (hanya sesi + permission).
- `GET /api/emis/status` hanya siswa; `GET /api/emis-gtk/status` baru, hanya GTK.
- Import GTK memakai `import_session_id`: halaman 1 tidak lagi menghapus data; baris `pending` sesi lama diganti atomik pada halaman terakhir; lock mencegah import bersamaan (`409 IMPORT_IN_PROGRESS`). `clear_staging` pada pull hanya menghapus baris `pending` (riwayat `committed` dipertahankan), butuh `staging:write` untuk token, dan diaudit (`staging.cleared`).
- Matching GTK: `emis_gtk_id` kini dihitung sebagai identitas; identifier ganda di master PINTU → `needs_review` (tidak dipilih sembarang). Berlaku juga untuk siswa.
- Item rekonsiliasi di-insert massal (500 baris/insert) dalam satu transaksi atomik.

### Matriks endpoint (sesi → permission; token → ability)
| Endpoint | Sesi | Token |
|---|---|---|
| GET staging | view_emis | staging:read |
| GET status / progress | view_emis | sync:read |
| GET reconciliation-batches(+items) | view_emis | reconcile:read |
| GET update-tasks | view_emis | tasks:read |
| POST reconcile | reconcile_emis | reconcile:run |
| POST pull | sync_emis | sync:trigger |
| POST cancel-sync | sync_emis | sync:cancel |
| POST import / import-raw | sync_emis | staging:write |
| POST token | sync_emis | credentials:update |
| POST commit, PATCH update-tasks | sync_emis (+break-glass di produksi) | ditolak |

### Benchmark rekonsiliasi siswa (sintetis, 10% pintu_only, ~13% berbeda; `EmisReconciliationBenchmarkTest`, `EMIS_BENCH_SIZES=...`)
| Baris | SQLite durasi / puncak memori / query | MySQL 8 durasi / puncak memori / query |
|---|---|---|
| 500 | 0,1 s / 98,5 MB / 8 | 0,48 s / 100,5 MB / 8 |
| 5.000 | 0,95 s / 114,5 MB / 43 | 2,64 s / 114,5 MB / 43 |
| 10.000 | 1,97 s / 136,5 MB / 81 | 4,97 s / 138,5 MB / 81 |
| 25.000 | 5,01 s / 194,5 MB / 195 | 12,45 s / 194,5 MB / 195 |

Transaksi tetap satu (atomik, tidak ada batch setengah jadi); durasi transaksi ≈ durasi di atas. Untuk >50.000 baris disarankan checkpoint batch terpisah (belum diimplementasi).

## WP0.5.13.1 — Sesi import GTK, integritas & penutupan test

### Kontrak import GTK (`POST /api/emis-gtk/import`) — **berubah**

| Aspek | Sebelum | Sesudah |
|---|---|---|
| Identitas sesi | satu kunci cache global (`emis_gtk:import:session`) | tabel `emis_import_sessions` (UUID per proses), terikat pada pemilik (token/user) |
| Halaman 1 | membuat sesi dan langsung menulis ke staging | membuat sesi, mengembalikan `import_session_id`; halaman disangga di `emis_import_session_pages` |
| Halaman > 1 | boleh tanpa ID (jatuh ke cache global) | **wajib** `import_session_id` (`422 IMPORT_SESSION_REQUIRED`) |
| Penggantian staging | pada halaman terakhir yang tiba | hanya bila **semua** `total_pages` halaman terkumpul dan `total_count` (jika dikirim) cocok; atomik dalam satu transaksi |
| Halaman ganda / tidak berurutan | tidak ditangani | idempoten per `(session_id, page)`; urutan bebas |
| Sesi kedaluwarsa (2 jam) | tidak ada | `410 IMPORT_SESSION_EXPIRED`, buffer dibuang, staging lama utuh |
| Sesi milik pihak lain | tidak dicegah | `404 IMPORT_SESSION_NOT_FOUND` |
| `total_pages` berubah / page > total | tidak divalidasi | `422 IMPORT_SESSION_MISMATCH` / `INVALID_PAGE` |
| Sesi selesai dikirim ulang | menimpa | `409 IMPORT_SESSION_CLOSED` |

Respons: `import_session_id`, `status`, `page`, `received_pages`, `total_pages`, `complete`, `saved`, `message`. Klien (bookmarklet GTK) harus menyimpan `import_session_id` dari respons halaman pertama dan mengirimkannya pada halaman berikutnya. Baris `committed` tidak pernah dihapus; hanya `pending` sesi lain yang diganti saat finalisasi. `clear_staging` pada `/pull` hanya menghapus `pending` dan tidak menyentuh buffer sesi, sehingga import berjalan tidak terganggu.

**Pemulihan:** import gagal/tidak lengkap/kedaluwarsa tidak mengubah staging. Mulai ulang dari halaman 1 (sesi baru). Sesi `open` yang terbengkalai kedaluwarsa otomatis saat dipakai lagi; bufernya tidak memengaruhi data aktif.

### Benchmark rekonsiliasi siswa (dieksekusi nyata, `EMIS_BENCH_SIZES=500,5000,10000,25000`)

| Baris | SQLite: detik / peak MB / Δ MB / query | MySQL 8: detik / peak MB / Δ MB / query |
|---|---|---|
| 500 | 0,16 / 98,5 / 4 / 8 | 0,47 / 100,5 / 4 / 8 |
| 5.000 | 0,95 / 114,5 / 10 / 43 | 2,02 / 114,5 / 8 / 43 |
| 10.000 | 1,94 / 136,5 / 10 / 81 | 6,00 / 138,5 / 10 / 81 |
| 25.000 | 4,74 / 194,5 / 28 / 195 | 13,08 / 194,5 / 28 / 195 |

Komposisi tiap ukuran N: identik 77,2%, berbeda 12,9%, pintu_only 10%, needs_review 0; total item = identik + berbeda + pintu_only (konsisten). Durasi transaksi = durasi total (satu transaksi atomik, sengaja tidak dipecah). Array `matchedStudentIds` tetap in-memory (±36 byte/ID; 25.000 ID jauh di bawah 2 MB dari Δ 28 MB). Di atas ±50 ribu baris diperlukan checkpoint batch eksplisit (belum diimplementasi).

## WP0.5.13.2 — Hardening konkurensi sesi import EMIS & retensi data

### 1. Arsitektur sesi import dua-fase (`EmisImportSessionService`)
- **Fase 1 (Penerimaan Halaman & Klaim Finalisasi — transaksi pendek ber-lock baris):**
  - Baris `emis_import_sessions` dikunci secara eksklusif (`SELECT ... FOR UPDATE` pada MySQL 8 InnoDB; transaksi tulis `NONE -> RESERVED` dengan `busy_timeout = 5000ms` pada SQLite) dan diverifikasi kepemilikannya (`owner_key` token/user).
  - Halaman dicek terhadap `(session_id, page)` beserta `payload_hash` (SHA-256 kanonis).
  - `received_pages` dan `received_rows` dihitung langsung dari agregat halaman unik di dalam lock sehingga bebas *lost update* saat dua halaman masuk bersamaan.
  - Saat `received_pages === total_pages` dan `total_count` valid, status sesi beralih secara atomik `open -> finalizing` (`finalizing_at` dicatat) sehingga **tepat satu proses pemenang** yang mengeksekusi penggantian staging.
- **Fase 2 (Penggantian Staging Atomik — di luar lock baris awal):**
  - Mengakuisisi lock lintas proses `emis_gtk:staging:mutation` (menyerialisasi terhadap import sesi lain, `POST /api/emis-gtk/pull` dengan `clear_staging=true`, dan `CommitEmisGtkStagingJob`).
  - Memverifikasi ulang kesinambungan nomor halaman `1..total_pages`, kecocokan `payload_hash` setiap halaman, dan `total_count`, lalu mengganti baris staging `pending` sesi lama secara atomik dalam satu transaksi database. Baris `committed` **tidak pernah** ditimpa atau dihapus.
  - Kegagalan di tengah proses membatalkan seluruh perubahan staging (*rollback* utuh), menyamarkan PII pada `failure_reason` dan log aplikasi, serta menandai sesi `failed`.

### 2. Kontrak request & response `POST /api/emis-gtk/import`
- **Aturan parameter:**
  - `data` (*wajib*, array minimal 1 objek item GTK; setiap item wajib memiliki `name`).
  - `page` (*opsional*, default `1`, integer `1..total_pages`).
  - `total_pages` (*opsional*, default `1`, integer `1..1000`): mengikat sesi pada halaman 1 dan **tidak boleh berubah** pada halaman berikutnya (`422 IMPORT_SESSION_MISMATCH`).
  - `total_count` (*opsional*, integer `>= 0` atau `null`): bila dikirim pada halaman 1, sesi mengikat nilai eksak tersebut; halaman berikutnya yang mengirim `total_count` berbeda ditolak (`422 IMPORT_SESSION_MISMATCH`); saat seluruh halaman terkumpul, jumlah seluruh item unik wajib sama persis dengan `total_count` (`422 IMPORT_COUNT_MISMATCH`).
  - `import_session_id` (*wajib untuk `page > 1`*, UUID sesi dari respons halaman 1; tanpa ID ditolak `422 IMPORT_SESSION_REQUIRED`).

- **Contoh request halaman pertama:**
  ```json
  {
    "page": 1,
    "total_pages": 2,
    "total_count": 3,
    "data": [
      { "uuid": "gtk-001", "name": "AHMAD FAUZI", "active": true },
      { "uuid": "gtk-002", "name": "SITI NURHALIZA", "active": true }
    ]
  }
  ```
- **Contoh response halaman antara (`200 OK`):**
  ```json
  {
    "success": true,
    "import_session_id": "019a2f4c-8d12-71a0-9f31-42c8a109e001",
    "status": "open",
    "saved": 0,
    "page": 1,
    "received_pages": 1,
    "total_pages": 2,
    "complete": false,
    "idempotent_replay": false,
    "message": "Halaman 1/2 diterima (1 halaman terkumpul)."
  }
  ```
- **Contoh response halaman terakhir / finalisasi berhasil (`200 OK`):**
  ```json
  {
    "success": true,
    "import_session_id": "019a2f4c-8d12-71a0-9f31-42c8a109e001",
    "status": "completed",
    "saved": 3,
    "page": 2,
    "received_pages": 2,
    "total_pages": 2,
    "complete": true,
    "message": "Import selesai: 3 data GTK masuk staging."
  }
  ```
- **Kode respons deterministik pada konflik, konkurensi, dan siklus hidup:**
  | HTTP | `error.code` | Kondisi & Perilaku Data |
  |---|---|---|
  | `200` | — (`idempotent_replay: true`) | Pengiriman ulang halaman yang sama dengan isi payload identik (urutan key JSON objek boleh berbeda, urutan elemen array tetap); tidak menambah `received_pages`/`received_rows`. |
  | `409` | `IMPORT_PAGE_CONFLICT` | Halaman yang sama dikirim ulang dengan isi atau urutan item berbeda; payload lama, hitungan, dan status sesi tidak berubah. |
  | `409` | `IMPORT_FINALIZING` | Request masuk saat sesi yang sama sedang memfinalisasi staging (`status = finalizing`, lease aktif). |
  | `409` | `IMPORT_SESSION_CLOSED` | Request masuk setelah sesi `completed` (menyertakan `complete: true` dan `saved: <saved_rows>` tanpa memproses ulang staging). |
  | `409` | `IMPORT_IN_PROGRESS` | Mutasi staging GTK lain sedang memegang lock `emis_gtk:staging:mutation`. |
  | `410` | `IMPORT_SESSION_EXPIRED` | Sesi `open`/`failed` melewati `expires_at` (default 120 menit); payload halaman dibersihkan. |
  | `422` | `IMPORT_COUNT_MISMATCH` | Seluruh `total_pages` diterima namun total baris unik `!= total_count`; sesi ditandai `failed`, staging lama tetap utuh. |
  | `422` | `IMPORT_SESSION_MISMATCH` | `total_pages` atau `total_count` berbeda dari metadata sesi. |
  | `500` | `IMPORT_FINALIZE_FAILED` | Kegagalan internal saat finalisasi; seluruh perubahan staging di-*rollback*, `failure_reason` dicatat tanpa PII. |

### 3. Prosedur pemulihan & kebijakan retensi terjadwal
- **Pemulihan saat gagal:**
  - Kegagalan transien saat finalisasi (`409 IMPORT_IN_PROGRESS`, `500 IMPORT_FINALIZE_FAILED`, atau timeout klien setelah lease `finalizing` 300 detik kedaluwarsa): selama sesi belum melewati `expires_at`, kirim ulang halaman terakhir yang identik ke `import_session_id` yang sama untuk menyelesaikan finalisasi tanpa mengunggah ulang halaman sebelumnya.
  - Kegagalan integritas payload/jumlah (`409 IMPORT_PAGE_CONFLICT`, `422 IMPORT_COUNT_MISMATCH`) atau kedaluwarsa (`410 IMPORT_SESSION_EXPIRED`): mulai sesi baru dari `page = 1` tanpa `import_session_id`.
- **Kebijakan retensi & Scheduled Cleanup (`php artisan emis:import-sessions:cleanup`, setiap 5 menit):**
  - Sesi `open` melewati `expires_at` (default **120 menit**) → diubah menjadi `expired` dan baris `emis_import_session_pages` dihapus; sesi yang sedang `finalizing` aktif **tidak pernah** disentuh oleh cleanup.
  - Sesi `finalizing` macet melampaui lease (default **300 detik**) → dipulihkan menjadi `failed` agar dapat di-retry atau mengikuti retensi.
  - Sesi `completed` → payload halaman dihapus segera saat finalisasi selesai; metadata sesi disimpan selama **30 hari** untuk jejak audit.
  - Sesi `failed` → payload halaman dipertahankan sementara selama **24 jam** untuk investigasi/retry, lalu payload dihapus sementara metadata sesi dipertahankan hingga **30 hari**.
  - Sesi terminal (`completed`, `expired`, `failed`) berusia > **30 hari** → metadata dihapus secara ber-batch dan idempoten; log hanya mencatat jumlah agregat tanpa PII.
