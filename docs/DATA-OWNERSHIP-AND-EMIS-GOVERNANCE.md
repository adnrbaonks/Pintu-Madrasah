# Kebijakan Data Ownership (SSOT) & Tata Kelola Integrasi EMIS — PINTU MADRASAH

Dokumen ini merupakan acuan arsitektur resmi kepemilikan data (*Data Ownership*), alur perubahan data master Siswa dan GTK, serta pemisahan proses **Import Awal**, **Rekonsiliasi Produksi**, dan **Pembaruan Outbound EMIS** pada **PINTU MADRASAH**.

---

## 1. Prinsip Arsitektur: PINTU MADRASAH sebagai Single Source of Truth (SSOT)

Ketika sistem telah beroperasi di tahap **produksi**:

1. **PINTU MADRASAH adalah Single Source of Truth (SSOT)** untuk seluruh master data internal madrasah (**Siswa**, **GTK**, dan **Struktur Akademik**).
2. **Peran EMIS Kemenag** dibatasi secara tegas menjadi tiga fungsi:
   - **Sumber Pengambilan Data Awal (*Initial Seeding*):** Digunakan pada tahap *development* atau migrasi awal untuk mengisi master data pertama kali tanpa input ulang manual.
   - **Sistem Pembanding (*Reconciliation Target*):** Digunakan untuk membandingkan data master PINTU dengan data EMIS tanpa menimpa master PINTU.
   - **Sistem Eksternal Tujuan Pembaruan (*Outbound Update Target*):** Diperbarui oleh operator EMIS berdasarkan data resmi yang telah dikelola, diverifikasi, dan disahkan di PINTU MADRASAH.
3. **Larangan Overwrite Otomatis:** Data dari staging/import EMIS **dilarang menimpa** master data PINTU yang sudah terisi secara otomatis pada tahap produksi.

---

## 2. Perbedaan Tahap Development/Migrasi vs Tahap Produksi

| Aspek | Tahap Development / Migrasi Awal | Tahap Produksi (Operasional) |
|---|---|---|
| **Pemilik Master Data** | Sedang dibangun di PINTU dari data awal EMIS | **PINTU MADRASAH (`owner_system = local`)** |
| **Fungsi Import EMIS** | Mengisi record baru & melengkapi kolom kosong (*smart-fill*) | Hanya menambah record baru (`emis_only`) atau mengisi kolom kosong; tidak menimpa data master yang sudah ada |
| **Perubahan Data Siswa** | Bulk import awal oleh operator | Dikelola oleh Operator Madrasah (`edit_students`) di PINTU dengan validasi dokumen & audit trail |
| **Perubahan Data GTK** | Bulk import awal oleh operator | Diajukan mandiri oleh GTK → Diverifikasi Kaur TU → Disetujui Kepala Madrasah → Diterapkan langsung ke Master PINTU |
| **Hubungan ke EMIS** | `initial_source_system = EMIS` | `production_sync_direction = outbound` (Operator memperbarui EMIS berdasarkan antrean `emis_update_tasks` di PINTU) |

---

## 3. Matriks Kepemilikan Data (`config/data_ownership.php`)

### 3.1 Entitas Siswa (`student`)

| Kelompok Atribut | Kolom | `owner_system` | `initial_source_system` | `production_sync_direction` | `change_policy` | Kontrol & Verifikasi |
|---|---|---|---|---|---|---|
| **Referensi Integrasi** | `emis_student_id` | `emis` | `EMIS Siswa` | `reference` | `read_only` | Kunci referensi teknis eksternal |
| **Identitas Utama & Sensitif** | `nisn`, `nik`, `kk_number` (`no_kk`), `name`, `birth_place`, `birth_date` | `local` (PINTU) | `EMIS Siswa` | `outbound` | `direct` | Wajib permission `edit_students`, wajib memiliki dokumen pendukung (`StudentDocument`) saat mengubah nilai yang sudah terisi, wajib alasan perubahan (`identity_change_reason`) bila terkunci/terverifikasi, tercatat di `activity_logs` & membuat `EmisUpdateTask` |
| **Identitas Dasar** | `gender`, `nis_local` | `local` (PINTU) | `EMIS Siswa` / `PINTU` | `outbound` / `local_only` | `direct` | Dikelola operator (`edit_students`) dengan jejak audit |
| **Keluarga & Wali** | `child_number`, `siblings_count`, `family_status`, `father_name`, `father_nik`, `father_birth_place`, `father_birth_date`, `father_education`, `father_occupation`, `father_income`, `mother_name`, `mother_nik`, `mother_birth_place`, `mother_birth_date`, `mother_education`, `mother_occupation`, `mother_income`, `guardian_name`, `guardian_nik`, `guardian_relation`, `guardian_phone`, `parent_phone` | `local` (PINTU) | `EMIS Siswa` | `outbound` | `direct` | Dikelola operator (`edit_students`), tercatat di audit trail & `EmisUpdateTask` |
| **Domisili & Administratif** | `address`, `rt_rw`, `village`, `district`, `city`, `province`, `postal_code`, `residence_type`, `transportation`, `distance_to_school`, `travel_time_minutes`, `phone`, `email`, `previous_school_name`, `previous_school_npsn`, `Previous_school_address`, `skhun_number`, `ijazah_number`, `kip_number`, `pkh_number`, `kks_number`, `pip_eligible`, `special_needs`, `hobby`, `ambition`, `height_cm`, `weight_kg`, `head_circumference_cm`, `entry_date`, `graduation_date`, `status` | `local` (PINTU) | `EMIS Siswa` / `PINTU` | `outbound` | `direct` | Dikelola operator (`edit_students`), tercatat di audit trail & `EmisUpdateTask` |

### 3.2 Entitas GTK (`staff`)

| Kelompok Atribut | Kolom | `owner_system` | `initial_source_system` | `production_sync_direction` | `change_policy` | Kontrol & Verifikasi |
|---|---|---|---|---|---|---|
| **Referensi Integrasi** | `emis_gtk_id` | `emis` | `EMIS GTK` | `reference` | `read_only` | Kunci referensi teknis eksternal |
| **Identitas Resmi & Kepangkatan (Risiko Tinggi)** | `nik`, `nip`, `nuptk`, `npk`, `birth_place`, `birth_date`, `civil_rank`, `civil_rank_tmt` | `local` (PINTU) | `EMIS GTK` | `outbound` | `approval_required` | Tidak dapat ditimpa langsung dari form edit cepat operator; wajib melalui Pengajuan Perubahan GTK + lampiran dokumen pendukung + Verifikasi Kaur TU + Persetujuan Kepala Madrasah |
| **Atribut Terkontrol Persetujuan** | `gender`, `tpg_status` | `local` (PINTU) | `EMIS GTK` | `outbound` | `approval_required` | Melalui alur persetujuan 2 tingkat atau validasi TPG (`validate_tpg`) |
| **Nama & Gelar** | `full_name_with_titles`, `first_title`, `last_title` | `local` (PINTU) | `EMIS GTK` | `outbound` | `direct` | Pada pengajuan mandiri GTK, perubahan `full_name_with_titles` wajib lampiran bukti dan persetujuan 2 tingkat; perubahan yang disetujui membuat `EmisUpdateTask` |
| **Kepegawaian, Pendidikan & Kontak** | `phone`, `email`, `employment_status`, `functional_position`, `highest_education`, `university_name`, `study_program`, `graduation_year`, `is_active` | `local` (PINTU) | `EMIS GTK` / `PINTU` | `outbound` | `direct` | Dapat diajukan oleh GTK melalui alur persetujuan 2 tingkat atau dikelola pejabat berwenang (`edit_staff`), serta membuat `EmisUpdateTask` |

### 3.3 Entitas Akademik & Aplikasi Satelit (`academic`)

| Entitas | `owner_system` | `production_sync_direction` | `change_policy` | Keterangan |
|---|---|---|---|---|
| `academic_years`, `classes`, `rombels`, `subjects` | `local` (PINTU) | `outbound` | `direct` | Master akademik PINTU yang didistribusikan ke aplikasi satelit |
| `student_grades` | `rdm` | `bidirectional` | `direct` | Data transaksi nilai milik RDM / Leger PINTU |

---

## 4. Pemisahan Tiga Proses EMIS

### 4.1 Proses A — Import Awal (`initial_import`)
- **Job & Endpoint:**
  - Siswa: `POST /api/emis/commit` → `CommitEmisStagingJob`
  - GTK: `POST /api/emis-gtk/commit` → `CommitEmisGtkStagingJob`
- **Metadata Batch (`emis_import_batches`):**
  - Mencatat `process_identifier` (`EMIS-IMP-...`), `entity_type`, `source_system`, `imported_by`, `imported_at`, `total_rows`, `created_count`, `updated_count`, `skipped_existing_count`, `matched_identical_count`, `difference_count`, dan `needs_review_count`.
- **Perilaku Aman Produksi:**
  - Secara default (`allow_overwrite = false`), import **hanya membuat record baru** atau **mengisi kolom yang masih kosong (`null`/string kosong)** pada master PINTU.
  - Apabila terdapat perbedaan antara nilai master PINTU dan nilai staging EMIS, nilai master PINTU **dipertahankan**, `skipped_existing_count` dinaikkan, dan perbedaan dicatat pada `emis_reconciliation_items`.
  - Jika pengguna mengirimkan `allow_overwrite = true` tanpa `overwrite_confirmed = true`, endpoint menolak dengan `422 OVERWRITE_CONFIRMATION_REQUIRED`.

### 4.2 Proses B — Rekonsiliasi Non-Destruktif (`reconciliation`)
- **Service & Endpoint:**
  - `EmisReconciliationService::reconcileStudents()` (`POST /api/emis/reconcile`)
  - `EmisReconciliationService::reconcileStaff()` (`POST /api/emis-gtk/reconcile`)
  - Riwayat batch & temuan: `GET /api/emis/reconciliation-batches`, `GET /api/emis-gtk/reconciliation-batches`
- **Klasifikasi Temuan (`emis_reconciliation_items.classification`):**
  1. `same`: Seluruh atribut yang dibandingkan identik antara master PINTU dan EMIS.
  2. `different`: Record cocok berdasarkan ID referensi/identitas, tetapi terdapat perbedaan nilai atribut (menyimpan `differences: { field: { pintu_value, emis_value } }`).
  3. `pintu_only`: Record ada di master PINTU namun belum ada di dataset pembanding EMIS.
  4. `emis_only`: Record ada di staging EMIS namun belum ada di master PINTU.
  5. `needs_review`: Baris staging tidak memiliki kunci identitas yang valid atau terjadi konflik identitas silang (mis. NISN merujuk ke Siswa A tetapi NIK merujuk ke Siswa B).
- **Jaminan Integritas:** Rekonsiliasi bersifat **100% read-only terhadap tabel master `students` dan `staff`**.

### 4.3 Proses C — Pembaruan Outbound EMIS (`emis_update_tasks`)
- **Pemicu Otomatis:**
  - Setiap perubahan atribut Siswa (`StudentController::update()`) atau GTK (`StaffChangeRequestService::apply()` setelah disetujui Kepala Madrasah, maupun `StaffService::update()`) yang memiliki `production_sync_direction = outbound` secara otomatis membuat tugas pembaruan pada tabel `emis_update_tasks` dengan status `pending`.
- **Endpoint Operasional Operator EMIS:**
  - Daftar tugas pembaruan: `GET /api/emis/update-tasks`, `GET /api/emis-gtk/update-tasks` (mendukung filter `?entity_type=student|staff`, `?entity_id=<uuid>`, `?status=pending|in_progress|completed|failed`).
  - Pembaruan status oleh operator: `PATCH /api/emis/update-tasks/{task}` atau `PATCH /api/emis-gtk/update-tasks/{task}` dengan payload:
    - `status`: `pending` | `in_progress` | `completed` | `failed`
    - `operator_notes`: catatan pengerjaan operator
    - `confirmation_reference`: nomor bukti/referensi pembaruan di portal EMIS
- **Jaminan Integritas:** Mengubah status `EmisUpdateTask` hanya memperbarui status pelacakan eksternal dan mencatat `ActivityLog` (`emis_outbound_status_updated`) tanpa pernah memodifikasi data master `students` maupun `staff` di PINTU.
