# Kebijakan Data Ownership (SSOT) & Tata Kelola Integrasi EMIS — PINTU MADRASAH (WP0.5.10)

Dokumen ini merupakan acuan arsitektur resmi kepemilikan data (*Data Ownership*), penegakan siklus hidup sistem (*Lifecycle Mode*), alur perubahan data master Siswa dan GTK, serta pemisahan proses **Import Awal (Development/Migrasi)**, **Rekonsiliasi Produksi**, dan **Pembaruan Outbound EMIS** pada **PINTU MADRASAH**.

---

## 1. Prinsip Arsitektur: PINTU MADRASAH sebagai Single Source of Truth (SSOT)

Ketika sistem telah beroperasi di tahap **produksi** (`PINTU_LIFECYCLE_MODE=production`):

1. **PINTU MADRASAH adalah Single Source of Truth (SSOT)** untuk seluruh master data internal madrasah (**Siswa**, **GTK**, dan **Struktur Akademik**).
2. **Peran EMIS Kemenag** dibatasi secara tegas menjadi tiga fungsi:
   - **Sumber Pengambilan Data Awal (*Initial Seeding*):** Hanya aktif pada mode `development` atau `migration` untuk mengisi master data pertama kali tanpa input ulang manual.
   - **Sistem Pembanding (*Reconciliation Target*):** Digunakan pada mode `production` untuk membandingkan data master PINTU dengan data staging EMIS secara *read-only* tanpa pernah mengubah master PINTU.
   - **Sistem Eksternal Tujuan Pembaruan (*Outbound Update Target*):** Diperbarui oleh operator EMIS berdasarkan antrean tugas `emis_update_tasks` dari data resmi yang telah dikelola, diverifikasi, dan disahkan di PINTU MADRASAH.
3. **Kunci Mutasi Produksi (*Production Commit Lock*):** Pada mode `production`, endpoint dan job commit EMIS (`POST /api/emis/commit`, `POST /api/emis-gtk/commit`, `CommitEmisStagingJob`, `CommitEmisGtkStagingJob`, serta `StudentController::commitEmisDetail()`) **diblokir secara tegas** — tidak boleh membuat record baru, tidak boleh mengisi kolom kosong (*smart-fill*), dan tidak boleh menimpa data master PINTU.

---

## 2. Siklus Hidup Sistem (`lifecycle_mode`) & Prosedur Break-Glass

Konfigurasi siklus hidup terpusat di `config/data_ownership.php` (`lifecycle_mode`, default `production` melalui `PINTU_LIFECYCLE_MODE`) dan ditegakkan oleh `DataOwnershipService`:

| Aspek | Mode `development` / `migration` | Mode `production` (Default Operasional) |
|---|---|---|
| **Pemilik Master Data** | Sedang dibangun di PINTU dari data awal EMIS | **PINTU MADRASAH (`owner_system = local`)** |
| **Commit Staging EMIS (`POST /api/emis*/commit`)** | Diizinkan bagi pengguna manusia ber-permission `sync_emis` (membuat record baru & mengisi kolom kosong; overwrite wajib `allow_overwrite=true` + `overwrite_confirmed=true`) | **DITOLAK (`403 EMIS_COMMIT_DISABLED_IN_PRODUCTION`)** kecuali melalui prosedur *Break-Glass Migration* |
| **Commit Detail Siswa EMIS (`POST /students/{student}/commit-emis`)** | Mengisi kolom yang masih kosong pada siswa | **DITOLAK** (dicatat di `activity_logs` sebagai `emis_detail_commit_blocked_in_production`) |
| **Akses Token Integrasi (`X-Integration-Token`)** | Hanya boleh staging/pull & baca rekonsiliasi; **dilarang** memicu `commit` (`403 INTEGRATION_TOKEN_COMMIT_FORBIDDEN`) | Hanya boleh staging/pull & baca rekonsiliasi sesuai domain; **dilarang** memicu `commit` atau mengubah status `EmisUpdateTask` |
| **Perubahan Data Siswa** | Bulk import awal oleh operator | Dikelola oleh Operator Madrasah (`edit_students`) di PINTU dengan dokumen pendukung **terverifikasi (`status = verified`)** & audit trail |
| **Perubahan Data GTK** | Bulk import awal oleh operator | Diajukan mandiri oleh GTK → Diverifikasi Kaur TU → Disetujui Kepala Madrasah → Diterapkan langsung ke Master PINTU |
| **Hubungan ke EMIS** | `initial_source_system = EMIS` | `production_sync_direction = outbound` (Operator memperbarui EMIS berdasarkan antrean `emis_update_tasks` di PINTU) |

### 2.1 Prosedur Darurat *Break-Glass Migration* di Produksi
Apabila pada masa transisi produksi diperlukan import darurat dari staging EMIS:
1. Fitur harus diaktifkan secara eksplisit di konfigurasi server: `PINTU_EMIS_BREAK_GLASS_ENABLED=true`.
2. Hanya dapat dieksekusi oleh **pengguna manusia terautentikasi sesi web** (bukan `integration_token`) yang memiliki permission `sync_emis` **dan** permission khusus `break_glass_emis_import` (permission ini tidak diberikan otomatis ke role mana pun).
3. Request wajib menyertakan:
   - `break_glass: true`
   - `break_glass_confirmed: true`
   - `break_glass_reason`: alasan tertulis minimal 15 karakter.
4. Sistem mencatat audit berisiko tinggi pada `activity_logs` (`emis_break_glass_commit_authorized`) dan `integration_logs` sebelum mengeksekusi job.

---

## 3. Matriks Kepemilikan Data (`config/data_ownership.php`)

### 3.1 Entitas Siswa (`student`)

| Kelompok Atribut | Kolom | `owner_system` | `initial_source_system` | `production_sync_direction` | `change_policy` | Kontrol & Verifikasi |
|---|---|---|---|---|---|---|
| **Referensi Integrasi** | `emis_student_id` | `emis` | `EMIS Siswa` | `reference` | `read_only` | Kunci referensi teknis eksternal |
| **Identitas Utama & Sensitif** | `nisn`, `nik`, `kk_number` (`no_kk`), `name`, `birth_place`, `birth_date` | `local` (PINTU) | `EMIS Siswa` | `outbound` | `direct` | Wajib permission `edit_students`, wajib memiliki dokumen pendukung (`StudentDocument`) berstatus **`verified`** dengan jenis dokumen yang relevan (`kartu_keluarga`, `akta_kelahiran`, `ijazah_smp`, `skhun`) saat mengubah nilai yang sudah terisi, wajib alasan perubahan (`identity_change_reason`) bila terkunci/terverifikasi, tercatat di `activity_logs` & membuat `EmisUpdateTask` |
| **Identitas Dasar** | `gender`, `nis_local` | `local` (PINTU) | `EMIS Siswa` / `PINTU` | `outbound` / `local_only` | `direct` | Dikelola operator (`edit_students`) dengan jejak audit |
| **Keluarga & Wali** | `child_number`, `siblings_count`, `family_status`, `father_name`, `father_nik`, `father_birth_place`, `father_birth_date`, `father_education`, `father_occupation`, `father_income`, `mother_name`, `mother_nik`, `mother_birth_place`, `mother_birth_date`, `mother_education`, `mother_occupation`, `mother_income`, `guardian_name`, `guardian_nik`, `guardian_relation`, `guardian_phone`, `parent_phone` | `local` (PINTU) | `EMIS Siswa` | `outbound` | `direct` | Dikelola operator (`edit_students`), tercatat di audit trail (`student_master_updated`) & `EmisUpdateTask` |
| **Domisili & Administratif** | `address`, `rt_rw`, `village`, `district`, `city`, `province`, `postal_code`, `residence_type`, `transportation`, `distance_to_school`, `travel_time_minutes`, `phone`, `email`, `previous_school_name`, `previous_school_npsn`, `previous_school_address`, `skhun_number`, `ijazah_number`, `kip_number`, `pkh_number`, `kks_number`, `pip_eligible`, `special_needs`, `hobby`, `ambition`, `height_cm`, `weight_kg`, `head_circumference_cm`, `entry_date`, `graduation_date`, `status` | `local` (PINTU) | `EMIS Siswa` / `PINTU` | `outbound` | `direct` | Dikelola operator (`edit_students`), tercatat di audit trail (`student_master_updated`) & `EmisUpdateTask` |

### 3.2 Entitas GTK (`staff`) — Penegakan `approval_required` Tanpa Bypass

`DataOwnershipService::isEditable('staff', $field)` memeriksa dua syarat sekaligus: `change_policy === 'direct'` **dan** `approval_required === false`. Dengan demikian, endpoint `PUT /gtk/{staff}` (`StaffController::update()`) **tidak dapat digunakan untuk mem-bypass alur persetujuan berjenjang GTK**.

| Kelompok Atribut | Kolom | `owner_system` | `production_sync_direction` | `change_policy` | `approval_required` | Kontrol & Verifikasi |
|---|---|---|---|---|---|---|
| **Referensi Integrasi** | `emis_gtk_id` | `emis` | `reference` | `read_only` | `false` | Kunci referensi teknis eksternal |
| **Identitas Resmi & Kepangkatan (Wajib Dokumen)** | `nik`, `nip`, `nuptk`, `npk`, `full_name_with_titles`, `birth_place`, `birth_date`, `civil_rank`, `civil_rank_tmt` | `local` (PINTU) | `outbound` | `approval_required` | `true` | Ditolak pada `PUT /gtk/{staff}`; wajib melalui Pengajuan Perubahan GTK + lampiran dokumen pendukung + Verifikasi Kaur TU + Persetujuan Kepala Madrasah → diterapkan ke Master PINTU & membuat `EmisUpdateTask` |
| **Atribut Biodata, Kepegawaian & Pendidikan** | `first_title`, `last_title`, `gender`, `phone`, `email`, `employment_status`, `functional_position`, `highest_education`, `university_name`, `study_program`, `graduation_year`, `tpg_status` | `local` (PINTU) | `outbound` | `approval_required` | `true` | Ditolak pada `PUT /gtk/{staff}`; wajib melalui alur persetujuan 2 tingkat GTK (atau endpoint khusus `validate_tpg` untuk `tpg_status`) → diterapkan ke Master PINTU & membuat `EmisUpdateTask` |
| **Status Operasional Akun/Kepegawaian** | `is_active` | `local` (PINTU) | `outbound` | `direct` | `false` | Satu-satunya atribut yang dapat diubah langsung melalui `PUT /gtk/{staff}` oleh pemegang permission `edit_staff`, tercatat di `ActivityLog` & membuat `EmisUpdateTask` |

### 3.3 Entitas Akademik & Aplikasi Satelit (`academic`)

| Entitas | `owner_system` | `production_sync_direction` | `change_policy` | Keterangan |
|---|---|---|---|---|
| `academic_years`, `classes`, `rombels`, `subjects` | `local` (PINTU) | `outbound` | `direct` | Master akademik PINTU yang didistribusikan ke aplikasi satelit |
| `student_grades` | `rdm` | `bidirectional` | `direct` | Data transaksi nilai milik RDM / Leger PINTU |

---

## 4. Pemisahan Tiga Proses EMIS & Isolasi Domain (`emis_siswa` vs `emis_gtk`)

### 4.1 Isolasi Domain Endpoint & Minimisasi PII
- Endpoint `/api/emis/*` terikat secara eksklusif pada `purpose = emis_siswa` dan `entity_type = student`.
- Endpoint `/api/emis-gtk/*` terikat secara eksklusif pada `purpose = emis_gtk` dan `entity_type = staff`.
- Upaya mengakses data lintas domain (mis. mengirim `?entity_type=staff` atau ID task GTK ke `/api/emis/*`) ditolak dengan `403 FORBIDDEN_ENTITY_SCOPE`.
- Respons `GET /reconciliation-batches` menyamarkan (*masking*) atribut PII sensitif (`nik`, `kk_number`, `nip`, `nuptk`, `npk`, `address`, `phone`, `email`) pada `pintu_snapshot` dan `emis_snapshot`.

### 4.2 Proses A — Import Awal (`initial_import`, Mode `development`/`migration`)
- **Job & Endpoint:**
  - Siswa: `POST /api/emis/commit` → `CommitEmisStagingJob`
  - GTK: `POST /api/emis-gtk/commit` → `CommitEmisGtkStagingJob`
- **Otorisasi:** Wajib sesi pengguna manusia dengan permission `sync_emis` (token integrasi ditolak dengan `403 INTEGRATION_TOKEN_COMMIT_FORBIDDEN`). Diblokir di mode `production` (`403 EMIS_COMMIT_DISABLED_IN_PRODUCTION`) kecuali prosedur *Break-Glass* terpenuhi.
- **Pelestarian Riwayat & Snapshot:** Baris `EmisStaging` dan `EmisGtkStaging` yang telah di-commit dipertahankan dengan `status = 'committed'` (tidak dihapus dari database), dan setiap `EmisReconciliationItem` menyimpan `pintu_snapshot` serta `emis_snapshot` saat import berlangsung.

### 4.3 Proses B — Rekonsiliasi Non-Destruktif (`reconciliation`, Aktif di Produksi)
- **Service & Endpoint:**
  - `EmisReconciliationService::reconcileStudents()` (`POST /api/emis/reconcile`)
  - `EmisReconciliationService::reconcileStaff()` (`POST /api/emis-gtk/reconcile`)
  - Riwayat batch & temuan: `GET /api/emis/reconciliation-batches`, `GET /api/emis-gtk/reconciliation-batches`
- **Prioritas Pencocokan & Skalabilitas:**
  - Siswa dicocokkan menggunakan hash index $O(1)$ per chunk dengan urutan prioritas: **1) `emis_student_id` → 2) `nisn` → 3) `nik`**.
  - GTK dicocokkan menggunakan hash index $O(1)$ per chunk dengan urutan prioritas: **1) `emis_gtk_id` → 2) `nik` → 3) `nuptk` → 4) `nip` → 5) `npk`**.
  - Apabila kunci identitas pada satu baris staging merujuk ke lebih dari satu kandidat berbeda di PINTU, baris tersebut diklasifikasikan sebagai `needs_review`.
- **Semantik Perbandingan Atribut:**
  - Hanya atribut yang benar-benar dikirim pada payload EMIS yang dibandingkan; atribut yang tidak dikirim dalam payload staging tidak dianggap sebagai pengosongan nilai.
  - Perbedaan nilai menyertakan `diff_type`: `emis_missing_value`, `pintu_missing_value`, atau `value_mismatch`.
- **Jaminan Integritas:** Rekonsiliasi bersifat **100% read-only terhadap tabel master `students` dan `staff`**.

### 4.4 Proses C — Pembaruan Outbound EMIS (`emis_update_tasks`)
- **Pemicu Otomatis:** Setiap perubahan atribut Siswa atau GTK di PINTU yang memiliki `production_sync_direction = outbound` membuat `EmisUpdateTask` berstatus `pending`.
- **Kontrol Pembaruan Status (`PATCH /api/emis*/update-tasks/{task}`):**
  - Hanya dapat dijalankan oleh pengguna manusia terautentikasi sesi web dengan permission `sync_emis` (token integrasi ditolak dengan `403 INTEGRATION_TOKEN_TASK_UPDATE_FORBIDDEN`).
  - Tugas yang telah berstatus `completed` dikunci dari pembukaan ulang sembarangan; transisi keluar dari `completed` wajib menyertakan `reopen_confirmed = true` dan `operator_notes` minimal 10 karakter (`422 INVALID_TASK_STATUS_TRANSITION`).
  - Mencatat audit `emis_outbound_status_updated` di `activity_logs` tanpa memodifikasi data master PINTU.

## WP0.5.11 — PII, UX direct edit GTK, pagination
- `App\Services\EmisPiiSanitizer` menurunkan daftar PII dari flag `sensitive` di `config/data_ownership.php` (+ nama/gelar/pendidikan) dan menyanitasi rekursif.
- `PUT /gtk/{staff}` dengan field campuran: field direct disimpan, field approval_required ditolak dan dilaporkan lewat flash `warning`/`rejected_fields` (bukan `success`).
