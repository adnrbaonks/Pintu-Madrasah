<?php

namespace App\Services;

use App\Models\Student;
use App\Models\AcademicYear;
use App\Models\EmisStaging;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EmisService
{
    const EMIS_BASE_URL     = 'https://api-emis.kemenag.go.id';
    const CACHE_KEY_SISWA   = 'emis:token:siswa';
    const CACHE_KEY_GTK     = 'emis:token:gtk';
    
    // Kunci rahasia dari frontend Kemenag EMIS JS
    const CRYPTO_KEY = 'a2c36eb2w1em50d6665dc5d61a68b400';
    const CRYPTO_IV = 'emisBase64IVkeys';

    /**
     * HTTP client dengan CA cert yang benar untuk menghindari TLS error.
     * PHP default menggunakan /etc/ssl/cert.pem yang tidak ada di sistem ini.
     */
    private function emisHttp(?string $token = null): \Illuminate\Http\Client\PendingRequest
    {
        $t = $token ?? $this->getEmisToken();
        $caFile = '/etc/ssl/certs/ca-certificates.crt';
        $client = Http::withOptions(['verify' => file_exists($caFile) ? $caFile : true])
            ->timeout(60);
        return $t ? $client->withToken($t) : $client;
    }

    public function getInstitutionId(): ?int
    {
        $token = $this->getEmisToken();
        if (! $token) return null;

        $parts = explode('.', $token);
        if (count($parts) !== 3) return null;

        $payload = json_decode(base64_decode($parts[1]), true);
        return $payload['identifiable_id'] ?? null;
    }

    public function storeEmisToken(string $token): bool
    {
        $ttl = (int) env('EMIS_TOKEN_TTL', 7200);
        Cache::put(self::CACHE_KEY_SISWA, $token, now()->addSeconds($ttl));
        return true;
    }

    /**
     * GTK: Menyimpan cookie Django session dari browser operator
     * Format: array ['csrftoken' => '...', 'sessionid' => '...']
     */
    public function storeGtkCookies(array $cookies): bool
    {
        $ttl = (int) env('EMIS_GTK_TOKEN_TTL', 28800); // 8 jam default
        Cache::put(self::CACHE_KEY_GTK, $cookies, now()->addSeconds($ttl));
        return true;
    }

    /** @deprecated — Gunakan storeGtkCookies() */
    public function storeGtkToken(string $token): bool
    {
        return $this->storeGtkCookies(['raw_token' => $token]);
    }

    public function getEmisToken(): ?string { return Cache::get(self::CACHE_KEY_SISWA); }
    
    /** Kembalikan array cookie GTK ['csrftoken'=>..., 'sessionid'=>...] */
    public function getGtkCookies(): ?array { return Cache::get(self::CACHE_KEY_GTK); }
    public function getGtkToken(): ?string { return Cache::get(self::CACHE_KEY_SISWA); } // alias
    public function hasActiveEmisToken(): bool { return Cache::has(self::CACHE_KEY_SISWA); }
    public function hasActiveGtkToken(): bool { return Cache::has(self::CACHE_KEY_GTK); }

    public function fetchAllStudents(int $page = 1, int $perPage = 100): array
    {
        $token = $this->getEmisToken();
        $institutionId = $this->getInstitutionId();

        if (! $token || ! $institutionId) {
            throw new \RuntimeException('Token EMIS tidak valid atau Institution ID tidak ditemukan.');
        }

        $response = $this->emisHttp($token)
            ->get(self::EMIS_BASE_URL . "/v1/students/institution/{$institutionId}/student/list", [
                'per_page' => $perPage,
                'page'     => $page,
            ]);

        if (! $response->successful()) {
            Log::error('[EMIS] Gagal menarik siswa', [
                'status' => $response->status(),
                'body' => $response->body()
            ]);
            return ['results' => [], 'total' => 0, 'last_page' => 1];
        }

        $data = $response->json('results', []);
        $total = $response->json('metadata.total', 0) ?: $response->json('meta.total', 0);
        $lastPage = $response->json('metadata.last_page') ?? $response->json('meta.last_page');
        
        if (! $lastPage && $total > 0) {
            $lastPage = (int) ceil($total / $perPage);
        }

        return [
            'results' => $data,
            'total' => $total,
            'last_page' => $lastPage ?: 1
        ];
    }

    /**
     * Parse data EMIS MURNI — hanya ambil field yang ada di EMIS, tanpa tebakan
     */
     /**
     * Tarik data mutasi (keluar/masuk)
     * endpoint: /v1/students/mutations?per_page=100&page=1&destination=true&type=out
     */
    public function fetchMutations(string $type = 'out', int $page = 1, int $perPage = 100): array
    {
        $token = $this->getEmisToken();

        if (! $token) {
            throw new \RuntimeException('Token EMIS tidak valid.');
        }

        $response = $this->emisHttp($token)
            ->get(self::EMIS_BASE_URL . "/v1/students/mutations", [
                'per_page'    => $perPage,
                'page'        => $page,
                'destination' => 'true',
                'type'        => $type,
            ]);

        if (! $response->successful()) {
            \Illuminate\Support\Facades\Log::error("[EMIS] Gagal menarik mutasi {$type}", [
                'status' => $response->status(),
                'body'   => $response->body()
            ]);
            return ['results' => [], 'total' => 0, 'last_page' => 1];
        }

        $data = $response->json('results', []);
        $total = $response->json('metadata.total', 0) ?: $response->json('meta.total', 0);
        $lastPage = $response->json('metadata.last_page') ?? $response->json('meta.last_page');
        
        if (!$lastPage && $total > 0) {
            $lastPage = (int) ceil($total / $perPage);
        }
        
        return [
            'results' => $data,
            'total'   => $total,
            'last_page' => $lastPage ?: 1
        ];
    }

    public function parseEmisStudentRaw(array $emisData): array
    {
        $rombelName = $emisData['learning_activity']['study_group']['name'] 
            ?? $emisData['study_group_name'] 
            ?? $emisData['tingkat'] 
            ?? 'Belum Masuk Rombel';
        
        $genderRaw = $emisData['m_gender'] ?? $emisData['gender'] ?? $emisData['jenis_kelamin'] ?? $emisData['jk'] ?? null;
        $gender = $this->parseGender($genderRaw);
        
        if ($gender === null) {
            $fullName = mb_strtoupper($emisData['full_name'] ?? '');
            $isFemale = preg_match('/\b(SITI|AI|SRI|PUTRI|NI|DEWI|NUR|AYU|MARIA|ANISA|ELIS|NUNUNG|IIS|NURUL|WINDA|YUNITA|INDAH|CANTIK|RINA|SINTA|TINA|LINA|ADINDA|SALSA|NABILA|RELITTA|ZAHRA|ANDINI|ASTI|AULIA|CHANTIKA|DERA|NISA|NAJWA|SYIFA|AURA|AZZAHRA)\b/', $fullName);
            $gender = $isFemale ? 'P' : 'L';
        }

        return [
            'emis_student_id'=> $emisData['id'] ?? null,
            'nisn'           => $emisData['nisn'] ?? null,
            'nik'            => $emisData['nik'] ?? null,
            'full_name'      => $emisData['full_name'] ?? 'Tanpa Nama',
            'gender'         => $gender,
            'birth_date'     => $emisData['birth_date'] ?? null,
            'birth_place'    => $emisData['birth_place'] ?? null,
            'rombel_name'    => $rombelName,
            'study_group_id' => $emisData['learning_activity']['study_group']['id'] ?? null,
        ];
    }

    public function parseGradeLevel(string $rombelName): ?string
    {
        $name = mb_strtoupper(trim($rombelName));

        // Tangkap romawi (VII-XII) atau angka arab (7-12) yang berdiri sendiri atau menempel huruf
        if (preg_match('/\b(VII|VIII|IX|X|XI|XII)\b|(?<!\d)(7|8|9|10|11|12)(?!\d)/i', $name, $m)) {
            $val = !empty($m[1]) ? $m[1] : $m[2];
            $map = [
                '7' => 'VII', '8' => 'VIII', '9' => 'IX', 
                '10' => 'X', '11' => 'XI', '12' => 'XII',
                'VII' => 'VII', 'VIII' => 'VIII', 'IX' => 'IX',
                'X' => 'X', 'XI' => 'XI', 'XII' => 'XII'
            ];
            return $map[strtoupper($val)] ?? null;
        }

        return null;
    }

    public function parseGender($raw): ?string
    {
        if (is_array($raw)) {
            $raw = $raw['name'] ?? $raw['label'] ?? $raw['id'] ?? (is_string($raw[0] ?? null) ? $raw[0] : null);
        }

        if ($raw === null || trim((string)$raw) === '') {
            return null;
        }

        $normalized = mb_strtolower(trim((string)$raw));
        
        if (in_array($normalized, ['l', '1', 'laki-laki', 'laki', 'male', 'pria'])) {
            return 'L';
        }
        
        if (in_array($normalized, ['p', '2', 'perempuan', 'wanita', 'female', '0'])) {
            return 'P';
        }

        return null;
    }

    /**
     * Meng-enkripsi EMIS Student ID ke format AES Base64 ganda yang diharapkan oleh Kemenag
     */
    public function encryptEmisId($id): string
    {
        if (empty($id)) return '';
        if (!is_numeric($id) && strlen($id) > 20) {
            return (string)$id; // Already encrypted
        }

        $encrypted = openssl_encrypt((string)$id, 'AES-256-CBC', self::CRYPTO_KEY, 0, self::CRYPTO_IV);
        return base64_encode($encrypted);
    }

    /**
     * Menarik semua data detail seorang siswa berdasarkan emis_student_id.
     */
    public function fetchStudentDetail(string $emisStudentId): ?array
    {
        $token = $this->getEmisToken();
        if (! $token) {
            throw new \RuntimeException('Token EMIS tidak valid atau sudah kedaluwarsa.');
        }

        $encryptedId = $this->encryptEmisId($emisStudentId);

        $response = $this->emisHttp($token)
            ->get(self::EMIS_BASE_URL . "/v1/students/students/{$encryptedId}");

        if (! $response->successful()) {
            Log::error("[EMIS] Gagal menarik detail siswa (ID: {$emisStudentId})", [
                'status' => $response->status(),
                'body' => $response->body()
            ]);
            return null;
        }

        $results = $response->json('results');

        // Tarik data parents secara spesifik untuk mendapatkan No. KK
        try {
            $parentsRes = $this->emisHttp($token)
                ->get(self::EMIS_BASE_URL . "/v1/students/students/{$encryptedId}/parents");
            
            if ($parentsRes->successful()) {
                $parentsData = $parentsRes->json('results');
                if (is_array($parentsData)) {
                    if (!isset($results['parents']) || !is_array($results['parents'])) {
                        $results['parents'] = [];
                    }
                    $results['parents'] = array_merge($results['parents'], $parentsData);
                }
            }
        } catch (\Throwable $e) {
            Log::warning("[EMIS] Gagal menarik detail parents untuk ID: {$emisStudentId}");
        }

        return $results;
    }

    // ── EMIS GTK: HTML Scraping via Django Session Cookie ──────────────────

    /**
     * GTK: Membuat HTTP client yang menggunakan cookie Django session
     * untuk mengakses halaman EMIS GTK yang di-render server-side (SSR).
     */
    private function gtkHttp(): \Illuminate\Http\Client\PendingRequest
    {
        $cookies = $this->getGtkCookies();
        if (! $cookies) {
            throw new \RuntimeException('Cookie EMIS GTK tidak tersedia.');
        }

        // Bangun cookie string dari array
        $cookieString = collect($cookies)
            ->map(fn($v, $k) => "$k=$v")
            ->implode('; ');

        $caFile = '/etc/ssl/certs/ca-certificates.crt';
        return Http::withOptions(['verify' => file_exists($caFile) ? $caFile : true])
            ->timeout(60)
            ->withHeaders([
                'Cookie'           => $cookieString,
                'Accept'           => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language'  => 'id-ID,id;q=0.9,en;q=0.8',
                'User-Agent'       => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36',
                'X-CSRFToken'      => $cookies['csrftoken'] ?? '',
                'Referer'          => 'https://emisgtk.kemenag.go.id/akun/manajemen/sekolah/',
            ]);
    }

    /**
     * GTK: Menarik daftar akun PTK dengan scraping HTML halaman EMIS GTK.
     * URL: https://emisgtk.kemenag.go.id/akun/manajemen/sekolah/?page={page}&per_page={perPage}
     */
    public function fetchGtkList(int $page = 1, int $perPage = 10): array
    {
        $baseUrl = 'https://emisgtk.kemenag.go.id/akun/manajemen/sekolah/';

        $response = $this->gtkHttp()->get($baseUrl, [
            'page'     => $page,
            'per_page' => $perPage,
        ]);

        if ($response->status() === 302 || str_contains($response->body(), 'login')) {
            throw new \RuntimeException('Session EMIS GTK sudah habis. Silakan login ulang dan kirim cookie baru.');
        }

        if (! $response->successful()) {
            Log::error('[EMIS GTK] Gagal fetch halaman', [
                'status' => $response->status(),
                'url'    => $baseUrl,
            ]);
            return ['results' => [], 'total' => 0, 'last_page' => 1];
        }

        $html = $response->body();
        $results = $this->parseGtkAccountTable($html);

        // Hitung total dari stats endpoint jika ada
        $total = $this->fetchGtkStatsTotal();
        $lastPage = $total > 0 ? (int) ceil($total / $perPage) : 1;

        return [
            'results'   => $results,
            'total'     => $total,
            'last_page' => $lastPage,
        ];
    }

    /**
     * GTK: Ambil total akun dari endpoint stats.
     * URL: https://emisgtk.kemenag.go.id/akun/manajemen/sekolah/stats/
     */
    public function fetchGtkStatsTotal(): int
    {
        try {
            $response = $this->gtkHttp()
                ->get('https://emisgtk.kemenag.go.id/akun/manajemen/sekolah/stats/');

            if ($response->successful()) {
                // Response bisa HTML atau JSON
                $json = $response->json();
                if ($json) {
                    return (int) ($json['total'] ?? $json['count'] ?? 0);
                }
                // Fallback: parse dari HTML
                preg_match('/Total Akun.*?(\d+)/s', $response->body(), $m);
                return (int) ($m[1] ?? 0);
            }
        } catch (\Exception $e) {
            Log::warning('[EMIS GTK] Gagal fetch stats: ' . $e->getMessage());
        }
        return 0;
    }

    /**
     * GTK: Parse tabel HTML dari halaman /akun/manajemen/sekolah/
     * Kolom tabel: No | Nama | Email/Phone | Status | Aksi
     * 
     * Dari page source:
     * - Nama ada di <div class="font-medium">NAMA</div>
     * - UUID ada di <div class="text-base-content/50 text-xs">UUID</div>
     * - Phone/Email ada di <td>91000082100218</td>
     * - Status dari form action yang mengandung UUID dan toggle-status
     */
    public function parseGtkAccountTable(string $html): array
    {
        $results = [];

        // Suppress HTML parse errors
        $prevErrors = libxml_use_internal_errors(true);
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_use_internal_errors($prevErrors);

        $xpath = new \DOMXPath($dom);

        // Temukan semua baris tabel <tr> yang berisi data (skip header)
        $rows = $xpath->query('//tbody/tr');

        if ($rows === false || $rows->length === 0) {
            // Fallback: cari semua <tr> yang punya <td>
            $rows = $xpath->query('//tr[td]');
        }

        foreach ($rows as $row) {
            $cells = $xpath->query('td', $row);
            if ($cells->length < 3) continue;

            // Sel ke-2 berisi nama dan UUID
            $cell2 = $cells->item(1);
            $name = '';
            $uuid = '';

            // Nama: <div class="font-medium">...
            $nameNodes = $xpath->query('.//div[contains(@class,"font-medium")]', $cell2);
            if ($nameNodes->length > 0) {
                $name = trim($nameNodes->item(0)->textContent);
            }

            // UUID: <div class="text-base-content/50 text-xs">...
            $uuidNodes = $xpath->query('.//div[contains(@class,"text-xs")]', $cell2);
            if ($uuidNodes->length > 0) {
                $uuid = trim($uuidNodes->item(0)->textContent);
            }

            // Phone/Email: sel ke-3
            $phone = trim($cells->item(2)->textContent ?? '');

            // Status: cek apakah ada input checkbox yang checked
            $statusInput = $xpath->query('.//input[@type="checkbox"]', $row);
            $isActive = false;
            if ($statusInput->length > 0) {
                $checked = $statusInput->item(0)->getAttribute('checked');
                $isActive = ($checked !== '' && $checked !== null && $checked !== false);
            }

            if (empty($name)) continue;

            $results[] = [
                'id'     => $uuid ?: uniqid(),
                'name'   => $name,
                'phone'  => $phone,
                'active' => $isActive,
                'uuid'   => $uuid,
            ];
        }

        return $results;
    }

    /**
     * GTK: Parse data hasil scraping menjadi format kolom emis_gtk_stagings
     */
    public function parseEmisGtkRaw(array $emisData): array
    {
        $name = $emisData['name'] ?? $emisData['full_name'] ?? 'Tanpa Nama';
        $uuid = $emisData['uuid'] ?? $emisData['id'] ?? null;

        return [
            'emis_gtk_id'           => $uuid,
            'nip'                   => null, // Tidak tersedia di halaman list
            'nik'                   => null, // Perlu kunjungi halaman detail
            'npk'                   => null,
            'nuptk'                 => null,
            'full_name_with_titles' => mb_strtoupper(trim($name)),
            'first_title'           => null,
            'last_title'            => null,
            'gender'                => null, // Tidak tersedia di halaman list
            'birth_place'           => null,
            'birth_date'            => null,
            'phone'                 => $emisData['phone'] ?? null,
            'email'                 => null,
            'employment_status'     => null, // Tidak tersedia di halaman list
            'functional_position'   => null,
            'highest_education'     => null,
            'raw_data'              => json_encode($emisData),
        ];
    }
}
