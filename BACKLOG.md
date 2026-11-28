# Backlog Pintu Madrasah — per 28 Nov 2026

## ✅ Selesai (Sprint 5 + WP0.5–WP17 + WP0.5.10 Data Ownership Enforcement)
- **WP0.5.10 — Data Ownership Enforcement (`config/data_ownership.php`, `DataOwnershipService`, `EmisController`, `EmisReconciliationService`):** Kunci mutasi EMIS di mode produksi (`PINTU_LIFECYCLE_MODE=production` memblokir `POST /api/emis*/commit`, `CommitEmisStagingJob`, `CommitEmisGtkStagingJob`, & `commitEmisDetail`), prosedur *Break-Glass Migration* ber-permission khusus (`break_glass_emis_import`), penutupan bypass edit langsung GTK pada `PUT /gtk/{staff}` (`change_policy === 'direct' && !approval_required`), isolasi tegas domain `/api/emis/*` (`student`) vs `/api/emis-gtk/*` (`staff`) beserta masking snapshot PII, kewajiban `StudentDocument` berstatus `verified`, serta pelestarian baris staging `committed` dan snapshot rekonsiliasi
- **Data Ownership SSOT & Tata Kelola EMIS (`config/data_ownership.php`, `EmisReconciliationService`):** PINTU MADRASAH sebagai Single Source of Truth (SSOT) untuk master data Siswa, GTK, dan Akademik saat produksi; pemisahan tegas Import Awal (`emis_import_batches` anti-overwrite produksi), Rekonsiliasi Non-Destruktif (`emis_reconciliation_items` dengan klasifikasi `same`, `different`, `pintu_only`, `emis_only`, `needs_review`), dan Antrean Pembaruan Outbound EMIS (`emis_update_tasks`)
- **API Gateway v1 & Transactional Outbox Webhook v2:** Key SHA-256 `pm_live_` tanpa fallback global key, scopes `students:identifiers` / `students:pii` / `staff:pii`, allowlist nested Resource, `Idempotency-Key`, Swagger, `lease_token` fencing, enkripsi secret webhook, kewajiban `ApiClient` aktif ber-scope PII untuk `include_pii=true`, proteksi SSRF outbound `WebhookUrlGuard` dengan verifikasi transport cURL *fail-closed* & IP pinning `CURLOPT_RESOLVE`, sanitasi PII rekursif, retry dead-letter `max_attempts` via `--retry-failed`, dan pengujian paralel multi-proses Redis queue worker
- **Layanan Mandiri GTK & Workflow Persetujuan 2 Tingkat:** Pengajuan mandiri GTK (dengan kewajiban dokumen pendukung pada perubahan identitas berisiko tinggi) → Verifikasi Kaur TU → Persetujuan Kepala Madrasah (dengan `lockForUpdate` anti-race-condition, pemisahan Admin Teknis dari pejabat penyetuju, penerapan langsung ke Master PINTU, pencatatan `EmisUpdateTask` outbound, & notifikasi `DB::afterCommit`)
- **RBAC Spatie Terpadu & Integritas Modul Tata Kelola:** 7 role resmi, 50 permission granular, penyaringan arsip digital berbasis SQL pra-limit (`ArchiveService::applyViewableScope()`), penguncian transaksi atomik pada finalisasi PKKM (`PkkmController::finalize()`) dan pengesahan/revisi notulen rapat (`MeetingController::approveMinutes()` & `reviseMinutes()`)
- BK, PPDB publik, ekspor RDM (CSV), backup otomatis + rotasi `APP_KEY` idempoten, folder Drive
- QR verifikasi publik (`/verify/{token}`)

## 🔴 Tier menengah (belum dikerjakan)
- [ ] QR biodata — DONE (8e87678), sisa: tempel QR di kartu ujian
- [ ] Bulk print biodata 1 bundel/kelas
- [ ] Paket ujian: label bangku, daftar hadir pengawas, berita acara
- [ ] Simpan persisten denah tempat duduk + cetak resmi
- [ ] Cetak lembar disposisi surat
- [ ] Throttle kuota Gemini + tombol manual override
- [x] Edit terkontrol data GTK & Siswa — DONE (PINTU sebagai SSOT produksi + Layanan Mandiri GTK + Persetujuan 2 Tingkat + Rekonsiliasi & Antrean Pembaruan Outbound EMIS)
- [ ] SKL + transkrip massal kelas 9
- [ ] Cetak leger wali kelas (PDF)

## 🟡 Tier rendah (belum dikerjakan)
- [ ] Yatim/piatu + ekonomi ortu di demografi
- [ ] Nomor register/buku agenda mutasi cetak
- [ ] Smart Importer Excel EMIS (.xlsx cadangan)
- [ ] Preview in-app + secure proxy dokumen Drive
- [ ] Agregasi SIMPEG otomatis untuk TPG
- [ ] 1-click copy NISN + sync balik status PPDB→EMIS

## 🗺️ Roadmap Pengembangan Berikutnya

### WP0.12 — Scheduling Management (Manajemen Jadwal Pelajaran)
**Status:** Planned / Belum dikembangkan  
**Posisi roadmap:** Setelah WP0.11 Examination Management dan sebelum WP0.13 Alumni Management.  
**Menu:** Master Data → Jadwal Pelajaran.

**Tujuan:** Menyediakan pengelolaan jadwal pelajaran terpusat dengan menggunakan data master PINTU MADRASAH sebagai referensi utama.

**Ruang lingkup:**
- Pengaturan periode jadwal berdasarkan tahun ajaran dan semester.
- Penyusunan jadwal per rombongan belajar, hari, jam pelajaran, mata pelajaran, guru pengampu, dan ruang kelas.
- Pengaturan jam pelajaran, waktu istirahat, serta hari efektif.
- Tampilan jadwal berdasarkan rombel dan guru.
- Cetak dan ekspor jadwal.
- Status draft dan dipublikasikan, termasuk kontrol perubahan setelah jadwal diterbitkan.
- Riwayat perubahan jadwal dan pencatatan pengguna yang melakukan perubahan.

**Validasi wajib:**
- Guru tidak boleh dijadwalkan mengajar pada lebih dari satu rombel dalam waktu yang sama.
- Rombel tidak boleh memiliki lebih dari satu mata pelajaran pada waktu yang sama.
- Ruangan tidak boleh digunakan oleh lebih dari satu rombel pada waktu yang sama.
- Validasi rentang jam, hari, periode, dan data referensi harus dilakukan di sisi server.

**Integrasi dan kepemilikan data:**
- Guru pengampu mengambil referensi dari Master GTK.
- Mata pelajaran mengambil referensi dari Master Mata Pelajaran.
- Kelas dan rombel mengambil referensi dari modul Kelas dan Rombel.
- Periode mengambil referensi dari Tahun Ajaran dan semester.
- E-Learning dapat mengonsumsi jadwal melalui kontrak integrasi/API; PINTU tidak mengambil alih transaksi pembelajaran E-Learning.

**Catatan implementasi untuk agent:**
1. Sebelum membuat fitur, audit repository untuk memastikan tidak ada modul, tabel, route, atau komponen jadwal yang sudah tersedia.
2. Reuse relasi dan data master yang sudah ada; jangan membuat duplikasi data GTK, mata pelajaran, kelas, rombel, atau tahun ajaran.
3. Rancang skema agar jadwal dapat dikelola per periode dan perubahan jadwal yang sudah dipublikasikan tetap memiliki jejak audit.
4. Terapkan RBAC/permission sesuai peran pengelola jadwal dan audit seluruh perubahan penting.
5. Tambahkan feature tests untuk validasi bentrok guru, rombel, dan ruang, termasuk kasus jadwal yang saling bersinggungan.
6. Jangan mengimplementasikan guru piket sebagai bagian WP0.12 tanpa persetujuan dan penetapan ruang lingkup tersendiri.

### Saran operasional lain (belum masuk urutan WP)
- [ ] Portal wali murid (cek nilai & berkas via NISN, tanpa login)
- [ ] Inventaris aset sarana (akreditasi)
- [ ] Presensi internal sementara (sambil menunggu SIPSMAD+RFID)

## ⏸️ Ditahan
- [ ] Perubahan UI premium menyeluruh (tunggu arahan desain)
- [ ] WhatsApp Gateway (setelah PTSP/webhook matang)
