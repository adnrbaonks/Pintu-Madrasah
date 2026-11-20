<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiPpdbService
{
    protected string $apiKey;

    protected string $model;

    public function __construct()
    {
        $this->apiKey = (string) Setting::get('gemini_api_key', config('services.gemini.api_key'));
        $this->model = (string) config('services.gemini.model', 'gemini-1.5-flash');
    }

    /**
     * Parse PPDB Registration Form (PDF/Image) using Gemini
     */
    public function parseDocument(string $filePath, string $mimeType): ?array
    {
        try {
            $base64File = base64_encode(file_get_contents($filePath));

            $prompt = "Anda adalah AI asisten untuk mengekstrak data dari formulir Pendaftaran Peserta Didik Baru (PPDB).\n"
                ."Ekstrak informasi yang tertulis (terutama tulisan tangan) pada dokumen yang terlampir ini. Kembalikan data dalam format JSON murni TANPA markdown block (tanpa ```json ... ```).\n"
                ."Perhatikan dengan seksama data yang diisi. Berikut adalah key yang HARUS dikembalikan:\n"
                ."- 'name': Nama Lengkap Siswa\n"
                ."- 'nisn': NISN Siswa (jika ada, biasanya 10 digit)\n"
                ."- 'nik': NIK Siswa (jika ada, biasanya 16 digit)\n"
                ."- 'gender': Jenis Kelamin ('L' untuk Laki-laki, 'P' untuk Perempuan)\n"
                ."- 'birth_place': Tempat Lahir (kota/kabupaten)\n"
                ."- 'birth_date': Tanggal Lahir (dalam format YYYY-MM-DD)\n"
                ."- 'religion': Agama (default: 'Islam')\n"
                ."- 'prev_school_name': Nama Sekolah Asal (misal: SDN ... atau MI ...)\n"
                ."- 'father_name': Nama Ayah\n"
                ."- 'father_nik': NIK Ayah\n"
                ."- 'father_job': Pekerjaan Ayah\n"
                ."- 'father_income': Penghasilan Rata-Rata Ayah tiap bulan (kembalikan teks rentangnya persis seperti yang dicentang/ditulis, misal '< Rp. 500.000' atau 'Rp. 500.001 - Rp. 1.000.000')\n"
                ."- 'mother_name': Nama Ibu\n"
                ."- 'mother_nik': NIK Ibu\n"
                ."- 'mother_job': Pekerjaan Ibu\n"
                ."- 'mother_income': Penghasilan Rata-Rata Ibu tiap bulan (format teks rentang seperti ayah)\n"
                ."- 'guardian_name': Nama Wali (jika ada)\n"
                ."- 'guardian_nik': NIK Wali (jika ada)\n"
                ."- 'guardian_job': Pekerjaan Wali (jika ada)\n"
                ."- 'address': Alamat Lengkap atau Alamat Rumah Lengkap\n"
                ."- 'rt': RT (2 atau 3 digit, misal '06')\n"
                ."- 'rw': RW (2 atau 3 digit, misal '02')\n"
                ."- 'village': Desa/Kelurahan (jika tidak ada di baris alamat, isi null)\n"
                ."- 'district': Kecamatan\n"
                ."- 'regency': Kabupaten/Kota (biasanya Kab.)\n\n"
                .'Pastikan format JSON yang dikembalikan valid.';

            $response = Http::timeout(60)->post("https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent?key={$this->apiKey}", [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $prompt],
                            [
                                'inlineData' => [
                                    'mimeType' => $mimeType,
                                    'data' => $base64File,
                                ],
                            ],
                        ],
                    ],
                ],
                'generationConfig' => [
                    'responseMimeType' => 'application/json',
                    'temperature' => 0.1,
                ],
            ]);

            if (! $response->successful()) {
                Log::error('[GEMINI PPDB] API Error', ['status' => $response->status(), 'body' => $response->body()]);

                return null;
            }

            $jsonResult = $response->json('candidates.0.content.parts.0.text');
            if (! $jsonResult) {
                return null;
            }

            return json_decode($jsonResult, true);

        } catch (\Exception $e) {
            Log::error('[GEMINI PPDB] Exception', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
