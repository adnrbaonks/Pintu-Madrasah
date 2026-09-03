<?php

namespace App\Services;

use App\Models\Student;
use App\Models\AcademicYear;
use App\Models\EmisStaging;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * EmisService — Layanan Integrasi EMIS 4.0 Kemenag
 *
 * Alur kerja:
 * 1. Bookmarklet di browser staf mengirim JWT token ke endpoint kita
 * 2. Token disimpan di Redis (Cache) dengan TTL sesuai .env
 * 3. Service ini menggunakan token tersebut untuk menarik data dari API EMIS
 * 4. Data di-parse dan di-upsert ke tabel students dengan Field Protection
 */
class EmisService
{
    // ── Konfigurasi EMIS ─────────────────────────────────────────────

    const EMIS_BASE_URL     = 'https://emis.kemenag.go.id';
    const EMIS_GTK_BASE_URL = 'https://emisgtk.kemenag.go.id';

    const CACHE_KEY_SISWA   = 'emis:token:siswa';
    const CACHE_KEY_GTK     = 'emis:token:gtk';

    // ── Token Management ─────────────────────────────────────────────

    /**
     * Simpan token EMIS Siswa ke Redis Cache
     */
    public function storeEmisToken(string $token): bool
    {
        $ttl = (int) env('EMIS_TOKEN_TTL', 7200);

        Cache::put(self::CACHE_KEY_SISWA, $token, now()->addSeconds($ttl));

        Log::info('[EMIS] Token siswa diterima dan disimpan', [
            'ttl_seconds' => $ttl,
            'expires_at'  => now()->addSeconds($ttl)->toDateTimeString(),
        ]);

        return true;
    }

    /**
     * Simpan token EMIS GTK ke Redis Cache
     */
    public function storeGtkToken(string $token): bool
    {
        $ttl = (int) env('EMIS_GTK_TOKEN_TTL', 7200);
        Cache::put(self::CACHE_KEY_GTK, $token, now()->addSeconds($ttl));

        Log::info('[EMIS GTK] Token GTK diterima dan disimpan', [
            'ttl_seconds' => $ttl,
        ]);

        return true;
    }

    /**
     * Ambil token EMIS Siswa dari Cache
     */
    public function getEmisToken(): ?string
    {
        return Cache::get(self::CACHE_KEY_SISWA);
    }

    /**
     * Ambil token EMIS GTK dari Cache
     */
    public function getGtkToken(): ?string
    {
        return Cache::get(self::CACHE_KEY_GTK);
    }

    /**
     * Cek apakah token EMIS Siswa masih aktif
     */
    public function hasActiveEmisToken(): bool
    {
        return Cache::has(self::CACHE_KEY_SISWA);
    }

    /**
     * Cek apakah token EMIS GTK masih aktif
     */
    public function hasActiveGtkToken(): bool
    {
        return Cache::has(self::CACHE_KEY_GTK);
    }

    // ── Data Pull: Siswa ─────────────────────────────────────────────

    /**
     * Tarik daftar rombel dari EMIS menggunakan token aktif.
     * Mengembalikan array rombel [{id, name, tingkat, ...}]
     */
    public function fetchRombelList(): array
    {
        $token = $this->getEmisToken();

        if (! $token) {
            throw new \RuntimeException('Token EMIS tidak tersedia. Silakan klik bookmarklet terlebih dahulu.');
        }

        $response = Http::withToken($token)
            ->timeout(30)
            ->get(self::EMIS_BASE_URL . '/api/v1/rombel/list');

        if (! $response->successful()) {
            Log::error('[EMIS] Gagal menarik daftar rombel', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            throw new \RuntimeException("EMIS API error: HTTP {$response->status()}");
        }

        return $response->json('data', []);
    }

    /**
     * Tarik data siswa per rombel dari EMIS.
     * Mengembalikan array siswa mentah dari EMIS.
     */
    public function fetchStudentsByRombel(string $rombelId): array
    {
        $token = $this->getEmisToken();

        if (! $token) {
            throw new \RuntimeException('Token EMIS tidak tersedia.');
        }

        $response = Http::withToken($token)
            ->timeout(60)
            ->get(self::EMIS_BASE_URL . '/api/v1/peserta-didik/list', [
                'rombel_id' => $rombelId,
                'per_page'  => 500,
            ]);

        if (! $response->successful()) {
            Log::error('[EMIS] Gagal menarik siswa rombel', [
                'rombel_id' => $rombelId,
                'status'    => $response->status(),
            ]);
            return [];
        }

        return $response->json('data', []);
    }

    // ── Parser & Selective Upsert ─────────────────────────────────────

    /**
     * Parse data mentah siswa dari EMIS ke format kolom DB kita.
     * Field mapping dari struktur JSON EMIS → kolom tabel students.
     */
    public function parseEmisStudentData(array $emisData): array
    {
        return [
            'nisn'           => $emisData['nisn'] ?? null,
            'nik'            => $emisData['nik_siswa'] ?? $emisData['nik'] ?? null,
            'name'           => $emisData['nama'] ?? $emisData['nama_siswa'] ?? null,
            'nickname'       => $emisData['nama_panggilan'] ?? null,
            'gender'         => $this->parseGender($emisData['jenis_kelamin'] ?? ''),
            'birth_place'    => $emisData['tempat_lahir'] ?? null,
            'birth_date'     => $this->parseDate($emisData['tanggal_lahir'] ?? ''),
            'religion'       => $emisData['agama'] ?? 'Islam',
            'citizenship'    => $emisData['kewarganegaraan'] ?? 'WNI',
            'address'        => $emisData['alamat_jalan'] ?? null,
            'village'        => $emisData['desa_kelurahan'] ?? null,
            'district'       => $emisData['kecamatan'] ?? null,
            'regency'        => $emisData['kabupaten_kota'] ?? null,
            'postal_code'    => $emisData['kode_pos'] ?? null,
            'mother_name'    => $emisData['nama_ibu_kandung'] ?? null,
            'father_name'    => $emisData['nama_ayah'] ?? null,
            'kk_number'      => $emisData['no_kk'] ?? null,
            'prev_school_name' => $emisData['sekolah_asal'] ?? null,
        ];
    }

    /**
     * Upsert siswa ke database dengan aturan Field Protection.
     *
     * ATURAN KRITIS (blueprint):
     * Jika is_locked = true ATAU is_verified_by_ai = true,
     * maka field identitas TIDAK di-overwrite oleh data mentah EMIS.
     */
    public function upsertStudent(array $emisData, ?AcademicYear $academicYear = null): array
    {
        $parsed = $this->parseEmisStudentData($emisData);
        $nisn   = $parsed['nisn'];

        if (! $nisn) {
            return ['status' => 'skipped', 'reason' => 'NISN kosong'];
        }

        $student = Student::withTrashed()->where('nisn', $nisn)->first();

        if ($student) {
            // ── Cek Field Protection ──────────────────────────────────
            if ($student->isIdentityFieldProtected()) {
                // Hanya update field non-identitas yang aman
                $safeFields = array_diff_key($parsed, array_flip(Student::protectedFields()));
                $before = $student->only(array_keys($safeFields));
                $student->update($safeFields);

                Log::info("[EMIS] Siswa NISN:{$nisn} — Field protection aktif, identitas dipertahankan.");
                return ['status' => 'protected_update', 'student_id' => $student->id];
            }

            // ── Update Normal (tidak terkunci) ────────────────────────
            $before = $student->toArray();
            $student->update($parsed);

            return ['status' => 'updated', 'student_id' => $student->id];
        }

        // ── Insert Baru ───────────────────────────────────────────────
        $parsed['status'] = 'active';
        $student = Student::create($parsed);

        Log::info("[EMIS] Siswa baru NISN:{$nisn} berhasil dibuat.");
        return ['status' => 'created', 'student_id' => $student->id];
    }

    // ── Helper Methods ────────────────────────────────────────────────

    private function parseGender(string $raw): string
    {
        $normalized = mb_strtolower(trim($raw));
        return in_array($normalized, ['l', 'laki-laki', 'laki', 'male', '1']) ? 'L' : 'P';
    }

    private function parseDate(string $raw): ?string
    {
        if (! $raw) return null;
        try {
            return \Carbon\Carbon::parse($raw)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }
}
