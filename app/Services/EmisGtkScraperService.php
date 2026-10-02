<?php

namespace App\Services;

use App\Models\EmisGtkStaging;
use App\Models\Setting;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Support\Facades\Log;
use Symfony\Component\DomCrawler\Crawler;

/**
 * EmisGtkScraperService
 *
 * Mengotomasi proses:
 * 1. Tukar emisSSO cookie → sessionid via /akun/sso/callback/
 * 2. Scrape halaman /ptk/manajemen-ptk/?per_page=100&page=N
 * 3. Parse HTML tabel → simpan ke emis_gtk_stagings
 */
class EmisGtkScraperService
{
    private const BASE_URL     = 'https://emisgtk.kemenag.go.id';
    private const CALLBACK_URL = self::BASE_URL . '/akun/sso/callback/';
    private const PTK_URL      = self::BASE_URL . '/ptk/manajemen-ptk/';
    private const PER_PAGE     = 100;

    private Client    $http;
    private CookieJar $cookieJar;

    public function __construct()
    {
        $this->cookieJar = new CookieJar();
        $this->http = new Client([
            'cookies'         => $this->cookieJar,
            'timeout'         => 30,
            'connect_timeout' => 10,
            'verify'          => false,
            'headers'         => [
                'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/126.0.0.0 Safari/537.36',
                'Accept'     => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            ],
        ]);
    }

    /**
     * Entry point utama — jalankan full sync.
     *
     * @return array{success: bool, imported: int, pages: int, message: string}
     */
    public function run(): array
    {
        $emisSso = Setting::get('emis_sso_token');

        if (blank($emisSso)) {
            return ['success' => false, 'imported' => 0, 'pages' => 0,
                    'message' => 'Token emisSSO belum dikonfigurasi di Pengaturan.'];
        }

        try {
            $sessionId = $this->getSessionId($emisSso);
            Log::info('EMIS GTK: Session ID diperoleh', ['length' => strlen($sessionId)]);

            [$imported, $pages] = $this->scrapeAllPages($sessionId);

            return [
                'success'  => true,
                'imported' => $imported,
                'pages'    => $pages,
                'message'  => "Berhasil mengimpor {$imported} data PTK dari {$pages} halaman.",
            ];
        } catch (\Throwable $e) {
            Log::error('EMIS GTK Scraper error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);

            return ['success' => false, 'imported' => 0, 'pages' => 0,
                    'message' => 'Error: ' . $e->getMessage()];
        }
    }

    // ─────────────────────────────────────────────────────────────────

    /**
     * Tukar emisSSO token menjadi sessionid EMIS GTK.
     */
    private function getSessionId(string $emisSso): string
    {
        $response = $this->http->post(self::CALLBACK_URL, [
            'json'    => ['sso_token' => $emisSso, 'next_url' => ''],
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
                'Origin'       => self::BASE_URL,
                'Referer'      => self::BASE_URL . '/login',
            ],
        ]);

        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException('Callback SSO gagal: HTTP ' . $response->getStatusCode());
        }

        // Ambil sessionid dari cookie jar
        foreach ($this->cookieJar as $cookie) {
            if ($cookie->getName() === 'sessionid') {
                return $cookie->getValue();
            }
        }

        throw new \RuntimeException('sessionid tidak ditemukan setelah callback SSO.');
    }

    /**
     * Scrape semua halaman manajemen-ptk.
     *
     * @return array{int, int} [total_imported, total_pages]
     */
    private function scrapeAllPages(string $sessionId): array
    {
        $totalImported = 0;
        $page          = 1;

        // Kosongkan staging sebelum sync ulang
        EmisGtkStaging::truncate();

        do {
            $html = $this->fetchPage($sessionId, $page);
            $rows = $this->parseRows($html);

            if (empty($rows)) {
                break;
            }

            foreach (array_chunk($rows, 50) as $chunk) {
                EmisGtkStaging::insert($chunk);
            }

            $totalImported += count($rows);

            $hasNext = $this->hasNextPage($html, $page);
            $page++;
        } while ($hasNext);

        return [$totalImported, $page - 1];
    }

    /**
     * GET satu halaman manajemen-ptk.
     */
    private function fetchPage(string $sessionId, int $page): string
    {
        $response = $this->http->get(self::PTK_URL, [
            'query'   => ['page' => $page, 'per_page' => self::PER_PAGE],
            'headers' => [
                'Cookie'  => "sessionid={$sessionId}",
                'Accept'  => 'text/html',
                'Referer' => self::BASE_URL . '/ptk/manajemen-ptk/',
            ],
        ]);

        return (string) $response->getBody();
    }

    /**
     * Parse baris data dari HTML tabel.
     */
    private function parseRows(string $html): array
    {
        $crawler = new Crawler($html);
        $rows    = [];
        $now     = now()->toDateTimeString();

        $crawler->filter('tbody tr')->each(function (Crawler $tr) use (&$rows, $now) {
            try {
                // 1. Nama & NIK
                $nama = trim($tr->filter('td:nth-child(2) .font-bold')->first()->text(''));
                $nik  = '';
                $tr->filter('td:nth-child(2) .text-xs span')->each(function (Crawler $span) use (&$nik) {
                    $text = $span->text('');
                    if (str_contains($text, 'NIK')) {
                        $nik = trim(str_replace('NIK', '', $text));
                    }
                });

                if (empty($nama)) return;

                // 2. PEG ID / NRG / NPK
                $pegId = $this->extractLabeledValue($tr, 'td:nth-child(3)', 'PEG ID');
                $nrg   = $this->extractLabeledValue($tr, 'td:nth-child(3)', 'NRG');
                $npk   = $this->extractLabeledValue($tr, 'td:nth-child(3)', 'NPK');

                // 3. Fungsi
                $fungsi = trim($tr->filter('td:nth-child(4) .badge')->first()->text(''));

                // 4. Kepegawaian (status + NIP)
                $kStatus = trim($tr->filter('td:nth-child(5) .badge')->first()->text(''));
                $nip     = $this->extractLabeledValue($tr, 'td:nth-child(5)', 'NIP');

                // 5. Kualifikasi
                $kualifikasi = trim($tr->filter('td:nth-child(6) .badge')->first()->text(''));

                // 6. Status Sertifikasi
                $sertifikasi = trim($tr->filter('td:nth-child(7) .badge')->first()->text(''));

                // 7. Status PTK (Aktif/Tidak)
                $statusPtk = trim($tr->filter('td:nth-child(8) .badge')->first()->text(''));

                $rows[] = [
                    'name'               => $nama,
                    'nik'                => $nik ?: null,
                    'nip'                => $nip ?: null,
                    'npk'                => $npk ?: null,
                    'nrg'                => ($nrg && $nrg !== '-') ? $nrg : null,
                    'peg_id'             => $pegId ?: null,
                    'function_role'      => $fungsi ?: null,
                    'employment_status'  => $kStatus ?: null,
                    'highest_education'  => $kualifikasi ?: null,
                    'is_sertifikasi'     => str_contains(strtolower($sertifikasi), 'sudah') || str_contains(strtolower($sertifikasi), 'ya'),
                    'k_aktif'            => str_contains(strtolower($statusPtk), 'aktif'),
                    'raw_data'           => null,
                    'created_at'         => $now,
                    'updated_at'         => $now,
                ];
            } catch (\Throwable) {
                // Skip baris bermasalah
            }
        });

        return $rows;
    }

    private function extractLabeledValue(Crawler $tr, string $tdSelector, string $label): string
    {
        $value = '';
        $tr->filter("{$tdSelector} div")->each(function (Crawler $div) use ($label, &$value) {
            $text = $div->text('');
            if (str_contains($text, $label)) {
                $value = trim(str_replace([$label, '-'], ['', ''], $text));
            }
        });
        return trim($value);
    }

    /**
     * Cek apakah ada halaman berikutnya di paginasi.
     */
    private function hasNextPage(string $html, int $currentPage): bool
    {
        $crawler = new Crawler($html);
        $found   = false;

        $crawler->filter('a[href]')->each(function (Crawler $a) use ($currentPage, &$found) {
            $href = $a->attr('href');
            if (str_contains($href, 'page=' . ($currentPage + 1))) {
                $found = true;
            }
        });

        return $found;
    }
}
