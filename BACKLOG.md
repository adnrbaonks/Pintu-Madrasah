# Backlog Pintu Madrasah — per 24 Nov 2026

## ✅ Selesai (Sprint 5 + WP0.5–WP17 Hardening & Audit)
- API Gateway v1 (`/api/v1/*`, key SHA-256 `pm_live_` tanpa fallback global key, scopes `students:identifiers` / `students:pii` / `staff:pii`, allowlist nested Resource, `Idempotency-Key`, Swagger) + Transactional Outbox Webhook v2 (`lease_token` fencing, enkripsi secret webhook, proteksi SSRF outbound `WebhookUrlGuard`, sanitasi PII rekursif, retry dead-letter `max_attempts` via `--retry-failed`, Redis queue worker)
- Layanan Mandiri GTK & Workflow Persetujuan 2 Tingkat (Verifikasi Kaur TU → Persetujuan Kepala Madrasah dengan `lockForUpdate` anti-race-condition & notifikasi `DB::afterCommit`, rekonsiliasi atomik `pending_external_sync` EMIS termasuk pengosongan nilai `null` eksplisit)
- RBAC Spatie Terpadu (7 role resmi, 50 permission granular mencakup Dokumen Privat, Arsip Digital, Rapat, Program Kerja, PKKM, dan Pengumuman, pembatasan `access_logs` arsip dengan `view_audit_logs`, serta pencabutan hak akses *real-time*)
- Arsip Digital Berversi (`/arsip-digital`, pembersihan file yatim saat transaksi DB gagal), Rapat & Notulen (`/rapat-dinas`, kunci notulen yang telah disahkan + histori revisi `meeting_minute_revisions`), Program Kerja & Anggaran (`/program-kerja`), Instrumen PKKM (`/pkkm`, scoping akses & validasi kelengkapan sebelum finalisasi), Pengumuman Internal (`/pengumuman`), Kalender Madrasah (`/kalender`)
- BK, PPDB publik, ekspor RDM (CSV), backup otomatis + rotasi `APP_KEY` idempoten, folder Drive
- QR verifikasi publik (`/verify/{token}`)

## 🔴 Tier menengah (belum dikerjakan)
- [ ] QR biodata — DONE (8e87678), sisa: tempel QR di kartu ujian
- [ ] Bulk print biodata 1 bundel/kelas
- [ ] Paket ujian: label bangku, daftar hadir pengawas, berita acara
- [ ] Simpan persisten denah tempat duduk + cetak resmi
- [ ] Cetak lembar disposisi surat
- [ ] Throttle kuota Gemini + tombol manual override
- [x] Edit terkontrol data GTK — DONE (Layanan Mandiri GTK + Persetujuan 2 Tingkat + Kamus Kepemilikan Data `pintu` vs `emis`)
- [ ] SKL + transkrip massal kelas 9
- [ ] Cetak leger wali kelas (PDF)

## 🟡 Tier rendah (belum dikerjakan)
- [ ] Yatim/piatu + ekonomi ortu di demografi
- [ ] Nomor register/buku agenda mutasi cetak
- [ ] Smart Importer Excel EMIS (.xlsx cadangan)
- [ ] Preview in-app + secure proxy dokumen Drive
- [ ] Agregasi SIMPEG otomatis untuk TPG
- [ ] 1-click copy NISN + sync balik status PPDB→EMIS

## 🆕 Saran baru (dari audit operasional)
- [ ] Jadwal pelajaran + guru piket mingguan + cetak
- [ ] Portal wali murid (cek nilai & berkas via NISN, tanpa login)
- [ ] Inventaris aset sarana (akreditasi)
- [ ] Presensi internal sementara (sambil menunggu SIPSMAD+RFID)

## ⏸️ Ditahan
- [ ] Perubahan UI premium menyeluruh (tunggu arahan desain)
- [ ] WhatsApp Gateway (setelah PTSP/webhook matang)
