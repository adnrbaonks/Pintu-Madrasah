# Panduan & Checklist Onboarding Aplikasi Satelit — PINTU MADRASAH

Panduan ini digunakan oleh administrator TU dan tim pengembang aplikasi satelit (**SIPSMAD**, **PTSP**, **E-Surat**, **RDM**, **E-Learning**, **WA Gateway**) saat menghubungkan layanan baru ke **PINTU MADRASAH**.

---

## 1. Informasi yang Wajib Disiapkan Tim Satelit

Sebelum administrator menerbitkan kredensial di PINTU MADRASAH, tim aplikasi satelit wajib mengisi data berikut:

- [ ] **Nama Aplikasi & Lingkungan:** (contoh: `SIPSMAD Production` / `PTSP Staging`)
- [ ] **PIC Teknis & Kontak:** (Nama pengembang/operator & nomor WhatsApp/email)
- [ ] **IP Server / Domain Satelit:** (Dicatat pada kolom *Catatan* di menu *API Clients*)
- [ ] **Daftar Scope yang Dibutuhkan (Prinsip *Least Privilege*):**
  - [ ] `students:read` — Data dasar siswa & rombel aktif
  - [ ] `students:pii` — Atribut sensitif siswa (NIK/KK) — *hanya jika wajib secara regulasi*
  - [ ] `staff:read` — Data dasar guru & tenaga kependidikan beserta jabatan
  - [ ] `staff:pii` — Atribut sensitif GTK (NIK)
  - [ ] `classes:read` — Daftar kelas, rombel, dan wali kelas
  - [ ] `academic-years:read` — Tahun ajaran aktif
- [ ] **Kebutuhan Langganan Webhook (Opsional):**
  - Target URL HTTPS penerima webhook (mis. `https://sipsmad.domain.sch.id/api/webhooks/pintu`)
  - Daftar event yang dilanggan (`student.created`, `student.updated`, `student.mutated`, `student.promoted`, `staff.created`, `staff.updated`, `staff.deactivated`, `staff.position.assigned`)
  - Apakah membutuhkan atribut PII (`include_pii`)? *(Default: Tidak)*

---

## 2. Langkah Penerbitan Kredensial oleh Administrator PINTU

1. Masuk ke dashboard **PINTU MADRASAH** sebagai `admin`.
2. Buka menu **Pengaturan → API Clients** (`/api-clients`).
3. Klik **Buat Key**, isi nama klien, pilih hanya scope yang disetujui, isi tanggal kedaluwarsa (disarankan rotasi tahunan), dan isi nama PIC pada kolom catatan.
4. Salin kunci `pm_live_<prefix8>_<secret40>` yang tampil satu kali dan kirimkan melalui jalur terenkripsi ke PIC aplikasi satelit.
5. Jika aplikasi satelit membutuhkan notifikasi *real-time*:
   - Buka menu **Pengaturan → Webhooks** (`/webhooks`).
   - Klik **Daftarkan URL**, pilih event, masukkan Target URL HTTPS, kaitkan ke API Client, dan biarkan opsi **Sertakan Atribut PII** tidak dicentang kecuali telah disetujui Kepala Madrasah.
   - Salin `whsec_...` (*signing secret*) ke konfigurasi server satelit.
   - Klik tombol **Kirim Ping Uji** dan pastikan status pengiriman di tabel bawah menunjukkan `HTTP 200`.

---

## 3. Checklist Implementasi di Sisi Aplikasi Satelit

- [ ] Simpan `PINTU_API_KEY` dan `PINTU_WEBHOOK_SECRET` di `.env` server satelit (jangan pernah di-commit ke repositori git).
- [ ] Kirimkan header `X-API-KEY` dan `X-Request-Id` pada setiap pemanggilan HTTP ke `/api/v1/*`.
- [ ] Gunakan parameter `?fields=` untuk meminta kolom yang benar-benar dipakai saja dan `?updated_since=` untuk sinkronisasi berkala yang hemat beban.
- [ ] Gunakan `GET /api/v1/students/by-id/{uuid}` apabila menangani siswa baru hasil PPDB yang belum memiliki NISN.
- [ ] Pada endpoint penerima webhook:
  - [ ] Verifikasi tanda tangan `X-Webhook-Signature-V2` menggunakan algoritma HMAC-SHA256 atas `"{X-Webhook-Timestamp}.{raw_body}"` dan tolak request dengan selisih timestamp > 300 detik.
  - [ ] Simpan `X-Webhook-Id` (`event_id`) yang telah diproses untuk mencegah pemrosesan ganda (*idempotent consumer*).
  - [ ] Kembalikan respons `200 OK` dalam waktu < 5 detik (proses tugas berat secara asinkron di antrean lokal aplikasi satelit).
