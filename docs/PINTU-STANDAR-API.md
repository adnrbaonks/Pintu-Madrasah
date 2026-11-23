# Standar API & Integrasi — PINTU MADRASAH (v1)

Dokumen ini mendefinisikan standar arsitektur, kontrak, autentikasi, otorisasi scope, perlindungan data pribadi (PII), dan keamanan integrasi untuk **PINTU MADRASAH** sebagai *Master Data Hub* bagi ekosistem aplikasi satelit madrasah (**SIPSMAD**, **PTSP**, **E-Surat**, **RDM**, dan **E-Learning**).

---

## 1. Versi & Kebijakan Perubahan

1. **Prefix Versi:** Seluruh endpoint publik aplikasi satelit berada di bawah `/api/v1/*`.
2. **Prinsip Aditif Lebih Dulu (*Additive-First*):**
   - Penambahan field baru, objek `meta`, query parameter opsional (`fields`, `include`, `updated_since`), dan endpoint baru dilakukan tanpa menghapus atau mengubah tipe data field lama pada versi `v1`.
   - Bentuk respons lama (`data`, properti paginator top-level Laravel untuk daftar terpaginasi, serta `message` pada respons gagal) **dipertahankan penuh** agar konsumen lama tetap berjalan tanpa perubahan kode.
3. **Deprecation & Sunset:**
   - Apabila sebuah endpoint atau perilaku dijadwalkan untuk diganti pada versi berikutnya, server mengirimkan header HTTP standar:
     - `Deprecation: true`
     - `Sunset: <HTTP-date>` (contoh: `Wed, 30 Jun 2027 23:59:59 GMT`)
     - `Link: <https://.../api/documentation>; rel="deprecation"`

---

## 2. Jejak Permintaan (`X-Request-Id`)

- Setiap request ke `/api/*` diproses oleh middleware `RequestId`.
- Jika klien mengirimkan header `X-Request-Id` (karakter alfanumerik/strip, maks 64 karakter), nilai tersebut dipertahankan; jika tidak, server membuat UUID v4 baru.
- `X-Request-Id` selalu dikembalikan pada header respons `X-Request-Id`, disertakan di `meta.request_id` (pada respons sukses) atau `request_id` (pada respons error), dan disuntikkan ke konteks log server.

---

## 3. Autentikasi & Siklus Hidup API Key

### 3.1 Format Kunci
Kunci API per-klien menggunakan format terstruktur:
```text
pm_live_<prefix8>_<secret40>
```
- `prefix8`: 8 karakter alfanumerik publik untuk pencarian cepat (*indexed lookup*) dan identifikasi di UI.
- `secret40`: 40 karakter rahasia acak kriptografis.

### 3.2 Penyimpanan & Verifikasi (Tanpa Plaintext)
- Database (`api_clients`) menyimpan:
  - `key_hash`: Hash `SHA-256` dari keseluruhan kunci (`unique`).
  - `key_prefix`: 8 karakter prefix.
  - `key_last4`: 4 karakter terakhir untuk tampilan tersembunyi (`pm_live_<prefix8>...<last4>`).
  - `last_rotated_at`, `expires_at`, `revoked_at`.
- **Dual-Read Transisi:** Selama masa transisi, kolom `api_key` dibuat `nullable`. Klien baru dan hasil rotasi langsung mengosongkan `api_key` (`null`) dan hanya menyimpan `key_hash`. Command `php artisan api-keys:hash-existing` mem-backfill kunci plaintext lama menjadi hash dan mengosongkan plaintext setelah terverifikasi.
- **Cara Pengiriman:**
  1. Header `X-API-KEY: pm_live_...` *(Direkomendasikan)*
  2. Header `Authorization: Bearer pm_live_...`
  3. Query parameter `?api_key=pm_live_...` *(Hanya untuk kompatibilitas legacy)*

### 3.3 Rotasi & Pencabutan (Revoke)
- **Rotasi (`regenerate`):** Menghasilkan prefix + secret baru, memperbarui `key_hash`, mencatat `last_rotated_at`, dan menampilkan kunci penuh **hanya satu kali** kepada administrator.
- **Revoke / Kedaluwarsa:** Kunci dengan `is_active = false`, `revoked_at != null`, atau `expires_at < now()` ditolak dengan `403 Forbidden` (`API_KEY_REVOKED` / `API_KEY_EXPIRED`).

---

## 4. Matriks Scope & Perlindungan PII

| Scope | Grup | Deskripsi |
|---|---|---|
| `students:read` | Siswa | Membaca daftar dan profil siswa |
| `students:pii` | Siswa (PII) | Akses eksplisit atribut sensitif siswa (NIK, No. KK, penghasilan orang tua) |
| `staff:read` | GTK | Membaca daftar dan profil guru & tenaga kependidikan |
| `staff:pii` | GTK (PII) | Akses eksplisit atribut sensitif GTK (NIK, gaji/tunjangan) |
| `classes:read` | Akademik | Membaca daftar rombongan belajar, kelas, dan wali kelas |
| `academic-years:read` | Akademik | Membaca informasi tahun ajaran aktif |

> **Catatan Kompatibilitas PII:** Untuk mencegah kerusakan pada aplikasi satelit yang sudah berjalan (SIPSMAD/PTSP), secara default field yang dikembalikan tetap kompatibel dengan kontrak `v1` awal. Klien dapat membatasi field yang diminta melalui parameter `?fields=id,nisn,name,status`.

---

## 5. Kontrak Envelope Respons

### 5.1 Respons Sukses Daftar Terpaginasi (`GET /api/v1/students`, `GET /api/v1/staff`)
Mempertahankan struktur paginator top-level Laravel **ditambah** objek `meta` standar:
```json
{
  "current_page": 1,
  "data": [
    {
      "id": "9d7a1c20-...",
      "nisn": "0081234567",
      "name": "Ahmad Fauzi",
      "status": "active"
    }
  ],
  "per_page": 50,
  "total": 711,
  "last_page": 15,
  "meta": {
    "request_id": "2c5f9b80-...",
    "generated_at": "2026-11-23T10:00:00Z",
    "pagination": {
      "page": 1,
      "per_page": 50,
      "total": 711,
      "last_page": 15
    }
  }
}
```

### 5.2 Respons Sukses Objek Tunggal / Koleksi Non-Paginasi
```json
{
  "data": { ... },
  "meta": {
    "request_id": "2c5f9b80-...",
    "generated_at": "2026-11-23T10:00:00Z"
  }
}
```

### 5.3 Respons Error Standar
Seluruh kegagalan autentikasi, otorisasi scope, validasi, maupun `404` mengembalikan JSON tanpa stack trace:
```json
{
  "success": false,
  "message": "Scope 'students:read' tidak dimiliki API Key ini.",
  "error": {
    "code": "FORBIDDEN_SCOPE"
  },
  "request_id": "2c5f9b80-..."
}
```

| HTTP Status | `error.code` | Kondisi |
|---|---|---|
| `401` | `MISSING_API_KEY` | Header/parameter API key tidak dikirim |
| `401` | `INVALID_API_KEY` | API key tidak dikenali |
| `401` | `UNAUTHORIZED_INTEGRATION_TOKEN` | Token integrasi EMIS tidak ada, kedaluwarsa, atau dicabut |
| `403` | `API_KEY_REVOKED` | API key telah dinonaktifkan atau dicabut |
| `403` | `API_KEY_EXPIRED` | Masa berlaku API key telah habis |
| `403` | `FORBIDDEN_SCOPE` | API key tidak memiliki scope yang disyaratkan endpoint |
| `404` | `RESOURCE_NOT_FOUND` | Data atau endpoint tidak ditemukan |
| `422` | `VALIDATION_ERROR` | Parameter input tidak valid |
| `429` | `RATE_LIMIT_EXCEEDED` | Melebihi batas 120 request/menit |

---

## 6. Parameter Query Standar (`/api/v1/*`)

1. **Paginasi (`page`, `per_page`):**
   - Default `per_page = 50`.
   - Maksimum `per_page = 100` (nilai di atas 100 otomatis dibatasi ke 100).
2. **Rekonsiliasi Inkremental (`updated_since`):**
   - Format ISO-8601 / tanggal (`?updated_since=2026-11-01T00:00:00Z`), memfilter baris dengan `updated_at >= updated_since`.
3. **Seleksi Kolom (`fields`):**
   - Daftar kolom dipisahkan koma (`?fields=id,nisn,name,status`), difilter terhadap allowlist model (`id` selalu disertakan).
4. **Relasi (`include`):**
   - Memuat relasi yang diizinkan (mis. `?include=currentCohort,documents` pada siswa atau `?include=positionAssignments` pada GTK).
5. **Pencarian Siswa tanpa NISN:**
   - `GET /api/v1/students/{nisn}` dipertahankan.
   - `GET /api/v1/students/by-id/{uuid}` disediakan untuk siswa baru/PPDB yang belum memiliki NISN.

---

## 7. Keamanan Endpoint Integrasi EMIS (`/api/emis/*` & `/api/emis-gtk/*`)

1. **Token Integrasi Terkelola (`integration_tokens`):**
   - Setiap bookmarklet EMIS menyematkan token integrasi berformat `pm_int_<prefix8>_<secret40>` yang diterbitkan khusus untuk pengguna operator (`purpose`: `emis_siswa` atau `emis_gtk`) dengan masa berlaku terbatas dan dapat dicabut (*revoke*) kapan saja dari UI.
2. **Middleware `AuthenticateIntegrationToken`:**
   - Memeriksa Bearer token (`Authorization: Bearer pm_int_...` atau header `X-Integration-Token`), memverifikasi hash SHA-256, memastikan `purpose` sesuai dan belum kedaluwarsa/dicabut.
   - **Transisi Same-Origin Session:** Selama masa transisi 1 rilis, pemanggilan internal dari UI browser operator yang sudah login (sesi web terautentikasi dengan role `admin`, `kaur_tu`, `staf`, `operator`) tetap diizinkan sebagai fallback. Pemanggilan lintas-origin (bookmarklet) tanpa token integrasi atau sesi yang sah ditolak dengan `401 Unauthorized`.
3. **Higiene Log & Validasi JWT:**
   - Token JWT EMIS maupun cookie `sessionid` **dilarang keras** ditulis ke file log.
   - Validasi JWT memeriksa struktur 3 segmen (`header.payload.signature`) serta klaim waktu kedaluwarsa `exp` pada payload JSON tanpa mengklaim verifikasi tanda tangan kriptografis pihak ketiga.
   - Seluruh aktivitas penerbitan token, penerimaan token, penarikan (*pull*), dan penyimpanan (*commit*) dicatat ke tabel audit `integration_logs` tanpa menyimpan nilai token rahasia.

---

## 8. Contoh Integrasi

### cURL
```bash
curl -s -H "X-API-KEY: pm_live_a1b2c3d4_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx" \
     -H "X-Request-Id: req-sipsmad-001" \
     "http://localhost:8000/api/v1/students?status=active&per_page=50&fields=id,nisn,name,status"
```

### PHP (Laravel Http Client)
```php
$response = Http::withHeaders([
    'X-API-KEY' => config('services.pintu.api_key'),
])->get(config('services.pintu.base_url').'/api/v1/staff', [
    'is_active' => true,
    'updated_since' => '2026-11-01T00:00:00Z',
]);
```
