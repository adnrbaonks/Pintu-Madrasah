# Backlog Pintu Madrasah — per 4 Okt 2026

## ✅ Selesai (Sprint 5 + audit)
- API Gateway (scopes, kelola key, Swagger) + Webhook Engine
- BK, PPDB publik, ekspor RDM (CSV), backup otomatis, folder Drive
- QR verifikasi publik (`/v/{token}`)

## 🔴 Tier menengah (belum dikerjakan)
- [ ] QR biodata — DONE (8e87678), sisa: tempel QR di kartu ujian
- [ ] Bulk print biodata 1 bundel/kelas
- [ ] Paket ujian: label bangku, daftar hadir pengawas, berita acara
- [ ] Simpan persisten denah tempat duduk + cetak resmi
- [ ] Cetak lembar disposisi surat
- [ ] Throttle kuota Gemini + tombol manual override
- [ ] Edit manual data GTK (saat ini read-only via sync)
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
