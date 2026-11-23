# Standar API & Integrasi — PINTU MADRASAH (v1.1 Final)

Dokumen ini mendefinisikan standar arsitektur, kontrak, autentikasi, otorisasi scope, perlindungan data pribadi (PII), keamanan integrasi EMIS, serta spesifikasi **Transactional Outbox & Webhook** untuk **PINTU MADRASAH** sebagai *Master Data Hub* bagi ekosistem aplikasi satelit madrasah (**SIPSMAD**, **PTSP**, **E-Surat**, **RDM**, dan **E-Learning**).

---

## 1. Versi & Kebijakan Perubahan

1. **Prefix Versi:** Seluruh endpoint publik aplikasi satelit berada di bawah `/api/v1/*`.
2. **Prinsip Aditif Lebih Dulu (*Additive-First*):**
   - Penambahan field baru, objek `meta`, query parameter opsional (`fields`, `include`, `updated_since`), dan endpoint baru dilakukan tanpa menghapus atau mengubah tipe data field lama pada versi `v1`.
   - Bentuk respons lama (`data`, properti paginator top-level Laravel untuk daftar terpaginasi, serta `message` pada respons gagal) **dipertahankan penuh** agar konsumen lama tetap berjalan tanpa perubahan kode.
3. **Deprecation & Sunset:**
   - Apabila sebuah endpoint atau perilaku dijadwalkan untuk diganti pada versi berikutnya, server mengirimkan header HTTP standar (middleware `api.deprecation`):
     - `Deprecation: true`
     - `Sunset: <HTTP-date>` (contoh: `Wed, 30 Jun 2027 23:59:59 GMT`)
     - `Link: </api/documentation>; rel="deprecation"`

---

## 2. Jejak Permintaan (`X-Request-Id`) & Idempotensi

- Setiap request ke `/api/*` diproses oleh middleware `RequestId`.
- Jika klien mengirimkan header `X-Request-Id` (karakter alfanumerik/strip/titik, maks 64 karakter), nilai tersebut dipertahankan; jika tidak, server membuat UUID v4 baru.
- `X-Request-Id` selalu dikembalikan pada header respons `X-Request-Id`, disertakan di `meta.request_id` (pada respons sukses) atau `request_id` (pada respons error), dan disuntikkan ke konteks log server.
- Request tulis (`POST`, `PUT`, `PATCH`) pada `/api/v1/*` mendukung header `Idempotency-Key` (disimpan selama 24 jam; replay mengembalikan header `X-Idempotent-Replay: true`).

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
- **Dual-Read Transisi:** Klien baru dan hasil rotasi langsung mengosongkan `api_key` (`null`) dan hanya menyimpan `key_hash`. Command `php artisan api-keys:hash-existing` mem-backfill kunci plaintext lama menjadi hash dan mengosongkan plaintext setelah terverifikasi.
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
| `students:read` | Siswa | Membaca daftar dan profil siswa (hanya atribut non-PII secara default) |
| `students:pii` | Siswa (PII) | Akses eksplisit atribut sensitif siswa (NIK, No. KK/`kk_number`, alamat, nama orang tua/wali, dokumen) via parameter `?fields=...` |
| `staff:read` | GTK | Membaca daftar dan profil guru & tenaga kependidikan (hanya atribut non-PII secara default) |
| `staff:pii` | GTK (PII) | Akses eksplisit atribut sensitif GTK (NIK, NUPTK, NIP, NPK, telepon, email, tempat/tanggal lahir, riwayat pendidikan & kepangkatan) via parameter `?fields=...` |
| `classes:read` | Akademik | Membaca daftar rombongan belajar, kelas, dan wali kelas |
| `academic-years:read` | Akademik | Membaca informasi tahun ajaran aktif |

> **Catatan Keamanan PII:**
> - Terapkan prinsip *deny by default*. Tanpa parameter `?fields=...`, respons API hanya mengembalikan atribut non-PII yang aman.
> - Atribut PII hanya dapat diakses apabila API client memiliki scope PII terkait (`students:pii` atau `staff:pii`) dan secara eksplisit dicantumkan pada parameter `?fields=...`.
> - Permintaan atribut PII tanpa kepemilikan scope yang sesuai akan diabaikan secara aman (*silent drop*) sehingga respons tetap valid tanpa membocorkan data pribadi.

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

## 6. Parameter Query Standar & Contoh `curl` Tiap Endpoint (`/api/v1/*`)

1. **Health Check & Dependency Status (`GET /api/v1/health`):**
   ```bash
   curl -s -H "X-Request-Id: req-health-01" \
        "http://localhost:8000/api/v1/health"
   ```
2. **Daftar Siswa Terpaginasi + Filter Kolom & Inkremental (`GET /api/v1/students`):**
   ```bash
   curl -s -H "X-API-KEY: pm_live_<prefix8>_<secret40>" \
        -H "X-Request-Id: req-sipsmad-001" \
        "http://localhost:8000/api/v1/students?status=active&academic_year_id=active&per_page=50&fields=id,nisn,name,status&updated_since=2026-11-01T00:00:00Z"
   ```
3. **Detail Siswa Berdasarkan NISN (`GET /api/v1/students/{nisn}`):**
   ```bash
   curl -s -H "X-API-KEY: pm_live_<prefix8>_<secret40>" \
        "http://localhost:8000/api/v1/students/0081234567"
   ```
4. **Detail Siswa Berdasarkan UUID — Termasuk Siswa Baru Tanpa NISN (`GET /api/v1/students/by-id/{uuid}`):**
   ```bash
   curl -s -H "X-API-KEY: pm_live_<prefix8>_<secret40>" \
        "http://localhost:8000/api/v1/students/by-id/9d7a1c20-4f1a-4b2c-9a11-0123456789ab"
   ```
5. **Daftar Guru & Tenaga Kependidikan + Relasi Jabatan (`GET /api/v1/staff`):**
   ```bash
   curl -s -H "X-API-KEY: pm_live_<prefix8>_<secret40>" \
        "http://localhost:8000/api/v1/staff?is_active=true&include=positionAssignments&fields=id,nip,nuptk,full_name_with_titles,employment_status,is_active,position_assignments"
   ```
6. **Daftar Rombongan Belajar & Wali Kelas (`GET /api/v1/classes`):**
   ```bash
   curl -s -H "X-API-KEY: pm_live_<prefix8>_<secret40>" \
        "http://localhost:8000/api/v1/classes?academic_year_id=active"
   ```
7. **Tahun Ajaran Aktif (`GET /api/v1/academic-years/active`):**
   ```bash
   curl -s -H "X-API-KEY: pm_live_<prefix8>_<secret40>" \
        "http://localhost:8000/api/v1/academic-years/active"
   ```

---

## 7. Transactional Outbox & Webhook (v2)

### 7.1 Katalog Event Resmi
| Event | Pemicu |
|---|---|
| `student.created` | Siswa baru ditambahkan atau mutasi masuk selesai |
| `student.updated` | Biodata siswa diperbarui |
| `student.mutated` | Siswa mutasi keluar, lulus, atau dikeluarkan |
| `student.promoted` | Kenaikan kelas massal |
| `staff.created` | Data GTK baru ditambahkan |
| `staff.updated` | Profil/biodata GTK diperbarui (melalui Master GTK maupun Persetujuan Layanan Mandiri) |
| `staff.deactivated` | Status keaktifan GTK dinonaktifkan (`is_active = false`) |
| `staff.position.assigned` | Penugasan jabatan struktural / SK baru ditetapkan |

### 7.2 Jaminan Transactional Outbox, Retry & Deduplikasi
- Setiap perubahan data siswa/GTK dicatat secara atomik ke tabel `outbox_events` di dalam transaksi database yang sama dan dijadwalkan melalui `DB::afterCommit()`. Apabila transaksi database batal (*rollback*), tidak ada webhook yang dikirim.
- **Retry Eksponensial:** Kegagalan pengiriman diulang otomatis hingga 5 kali (`max_attempts`) dengan jadwal backoff `[10, 30, 120, 300, 900]` detik menggunakan `event_id` yang sama.
- **Deduplikasi:** PINTU menjamin satu `(webhook_subscription_id, event_id)` yang sudah berhasil (`delivered = true`) tidak akan dikirim ulang. Aplikasi satelit wajib menyimpan `event_id` (`X-Webhook-Id`) untuk idempotensi di sisi penerima.
- **Perlindungan PII Default:** Secara default (`include_pii = false`), atribut PII (`nik`, `family_card_number`, penghasilan orang tua) disensor otomatis dari payload `data`.

### 7.3 Struktur Payload & Header HTTP Webhook
```json
{
  "event": "staff.updated",
  "event_id": "7b2e19a4-9012-4a8e-bc11-556677889900",
  "occurred_at": "2026-11-23T10:00:00Z",
  "version": 1,
  "data": {
    "id": "11111111-2222-3333-4444-555555555555",
    "nip": "198501012010011001",
    "nuptk": "1234567890123456",
    "name": "Ahmad Fauzi, S.Pd., M.Pd.",
    "employment_status": "PNS",
    "functional_position": "Guru Mata Pelajaran",
    "is_active": true,
    "changed_fields": ["full_name_with_titles", "last_title"]
  }
}
```

Header yang dikirim pada setiap request `POST`:
- `X-Webhook-Id`: UUID unik event (`event_id`)
- `X-Webhook-Event`: Nama event (mis. `staff.updated`)
- `X-Webhook-Timestamp`: Unix epoch timestamp saat pengiriman
- `X-Webhook-Signature`: `sha256=<hmac_sha256(raw_body, secret)>` *(v1 — kompatibilitas transisi)*
- `X-Webhook-Signature-V2`: `v2=<hmac_sha256(timestamp + "." + raw_body, secret)>` *(v2 — anti-replay, direkomendasikan)*

### 7.4 Contoh Verifikasi Webhook di Sisi Satelit (PHP / Laravel)
```php
<?php

use Illuminate\Http\Request;

function verifyPintuWebhook(Request $request, string $secret, int $toleranceSeconds = 300): bool
{
    $timestamp = (int) $request->header('X-Webhook-Timestamp');
    $signatureV2 = (string) $request->header('X-Webhook-Signature-V2');
    $rawBody = $request->getContent();

    if (abs(time() - $timestamp) > $toleranceSeconds) {
        return false; // Tolak replay di luar jendela 5 menit
    }

    $expected = 'v2='.hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);

    return hash_equals($expected, $signatureV2);
}
```

### 7.5 Contoh Verifikasi Webhook di Sisi Satelit (Node.js / Express)
```javascript
import crypto from 'node:crypto';

export function verifyPintuWebhook(rawBodyBuffer, headers, secret, toleranceSeconds = 300) {
  const timestamp = Number(headers['x-webhook-timestamp'] || 0);
  const signatureV2 = String(headers['x-webhook-signature-v2'] || '');
  const nowSeconds = Math.floor(Date.now() / 1000);

  if (Math.abs(nowSeconds - timestamp) > toleranceSeconds) {
    return false;
  }

  const signedPayload = `${timestamp}.${rawBodyBuffer.toString('utf8')}`;
  const expected = 'v2=' + crypto.createHmac('sha256', secret).update(signedPayload).digest('hex');

  const expectedBuf = Buffer.from(expected, 'utf8');
  const providedBuf = Buffer.from(signatureV2, 'utf8');

  return expectedBuf.length === providedBuf.length && crypto.timingSafeEqual(expectedBuf, providedBuf);
}
```

---

## 8. Keamanan Endpoint Integrasi EMIS (`/api/emis/*` & `/api/emis-gtk/*`)

1. **Token Integrasi Terkelola (`integration_tokens`):**
   - Setiap bookmarklet EMIS menyematkan token integrasi berformat `pm_int_<prefix8>_<secret40>` yang diterbitkan khusus untuk pengguna operator (`purpose`: `emis_siswa` atau `emis_gtk`) dengan masa berlaku terbatas dan dapat dicabut (*revoke*) kapan saja dari UI.
2. **Middleware `AuthenticateIntegrationToken`:**
   - Memeriksa Bearer token (`Authorization: Bearer pm_int_...` atau header `X-Integration-Token`), memverifikasi hash SHA-256, memastikan `purpose` sesuai dan belum kedaluwarsa/dicabut.
   - **Transisi Same-Origin Session:** Pemanggilan internal dari UI browser operator yang sudah login (sesi web terautentikasi dengan role `admin`, `kaur_tu`, `staf`, `operator`) tetap diizinkan sebagai fallback.
3. **Higiene Log & Validasi JWT:**
   - Token JWT EMIS maupun cookie `sessionid` **dilarang keras** ditulis ke file log.
   - Validasi JWT memeriksa struktur 3 segmen (`header.payload.signature`) serta klaim waktu kedaluwarsa `exp` pada payload JSON.
   - Seluruh aktivitas penerbitan token, penerimaan token, penarikan (*pull*), dan penyimpanan (*commit*) dicatat ke tabel audit `integration_logs` tanpa menyimpan nilai token rahasia.
