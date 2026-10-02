<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiVerificationService
{
    protected string $apiKey;
    protected string $model = 'gemini-3.6-flash';

    public function __construct()
    {
        $this->apiKey = \App\Models\Setting::get('gemini_api_key', env('GEMINI_API_KEY'));
    }

    /**
     * Verifikasi dokumen siswa (Akta Kelahiran / KK) dan kembalikan data ekstraksi
     * serta skor kecocokan dengan data saat ini.
     */
    public function verifyDocument(string $filePath, string $mimeType, array $currentStudentData): ?array
    {
        try {
            $base64Image = base64_encode(file_get_contents($filePath));

            $prompt = "Anda adalah AI asisten untuk verifikasi dokumen resmi administrasi madrasah di Indonesia (Kartu Keluarga, Akta Kelahiran, atau Ijazah SD/MI).\n"
                . "Ekstrak informasi penting dari dokumen yang terlampir ini dalam format JSON yang ketat tanpa markup markdown tambahan. Kunci yang harus ada:\n"
                . "- 'nik': NIK Siswa (16 digit)\n"
                . "- 'nisn': NISN Siswa (10 digit numerik)\n"
                . "- 'name': Nama Lengkap Siswa\n"
                . "- 'birth_place': Tempat Lahir\n"
                . "- 'birth_date': Tanggal Lahir (YYYY-MM-DD)\n"
                . "- 'mother_name': Nama Ibu Kandung\n"
                . "- 'father_name': Nama Ayah\n"
                . "- 'prev_school_name': Nama Sekolah Asal (dari Ijazah)\n"
                . "- 'document_type': Jenis Dokumen (kk / akta_kelahiran / ijazah_sd / unknown)\n\n"
                . "Bandingkan juga data yang diekstrak dengan data referensi berikut dan berikan persentase kecocokan (0-100) di key 'match_score', serta penjelasan singkat di 'notes':\n"
                . json_encode($currentStudentData);

            $response = Http::timeout(60)->post("https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent?key={$this->apiKey}", [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $prompt],
                            [
                                'inlineData' => [
                                    'mimeType' => $mimeType,
                                    'data' => $base64Image
                                ]
                            ]
                        ]
                    ]
                ],
                'generationConfig' => [
                    'responseMimeType' => 'application/json',
                    'temperature' => 0.1,
                ]
            ]);

            if (!$response->successful()) {
                Log::error('[GEMINI] API Error', ['status' => $response->status(), 'body' => $response->body()]);
                return null;
            }

            $jsonResult = $response->json('candidates.0.content.parts.0.text');
            if (!$jsonResult) return null;

            return json_decode($jsonResult, true);
            
        } catch (\Exception $e) {
            Log::error('[GEMINI] Exception', ['error' => $e->getMessage()]);
            return null;
        }
    }
}
