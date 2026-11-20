<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\Student;
use Google_Client;
use Google_Service_Drive;
use Google_Service_Drive_DriveFile;
use Illuminate\Support\Facades\Log;

class GoogleDriveService
{
    protected Google_Client $client;

    protected Google_Service_Drive $service;

    protected ?string $folderId;

    public function __construct()
    {
        $this->client = new Google_Client;

        // ── Prioritas 1: Service Account (sesuai blueprint + .env.example) ──
        $saJson = env('GOOGLE_SERVICE_ACCOUNT_JSON');
        if ($saJson && file_exists($saJson)) {
            $this->client->setAuthConfig($saJson);
            $this->client->addScope(Google_Service_Drive::DRIVE_FILE);
        } else {
            // ── Fallback: OAuth refresh-token (legacy GDRIVE_*) ──
            if (env('GDRIVE_CLIENT_ID')) {
                $this->client->setClientId(env('GDRIVE_CLIENT_ID'));
            }
            if (env('GDRIVE_CLIENT_SECRET')) {
                $this->client->setClientSecret(env('GDRIVE_CLIENT_SECRET'));
            }
            if (env('GDRIVE_REFRESH_TOKEN')) {
                $this->client->refreshToken(env('GDRIVE_REFRESH_TOKEN'));
            }
        }

        $this->service = new Google_Service_Drive($this->client);
        $this->folderId = env('GOOGLE_DRIVE_FOLDER_ID') ?: env('GDRIVE_FOLDER_ID');
    }

    /**
     * Upload a file to Google Drive and return the file ID and Web View Link.
     */
    public function uploadFile(string $filePath, string $mimeType, string $originalName, ?string $folderId = null): ?array
    {
        try {
            if (! $this->folderId && ! $folderId) {
                Log::warning('[GDRIVE] Folder ID belum dikonfigurasi (GOOGLE_DRIVE_FOLDER_ID)');

                return null;
            }

            $file = new Google_Service_Drive_DriveFile;
            $file->setName($originalName);
            $file->setParents([$folderId ?? $this->folderId]);

            $result = $this->service->files->create(
                $file,
                [
                    'data' => file_get_contents($filePath),
                    'mimeType' => $mimeType,
                    'uploadType' => 'multipart',
                    'fields' => 'id, webViewLink',
                ]
            );

            // Set file permissions to 'anyone with link can view'
            $permission = new \Google_Service_Drive_Permission;
            $permission->setType('anyone');
            $permission->setRole('reader');
            $this->service->permissions->create($result->getId(), $permission);

            Log::info('[GDRIVE] File uploaded successfully', ['file_id' => $result->getId()]);

            return [
                'gdrive_file_id' => $result->getId(),
                'gdrive_view_url' => $result->getWebViewLink(),
            ];
        } catch (\Exception $e) {
            Log::error('[GDRIVE] Error uploading file', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /** Cache id folder per path selama request. */
    protected array $folderCache = [];

    /**
     * Pastikan struktur folder bertingkat ada, kembalikan ID folder daun.
     * Contoh: "ARSIP_DIGITAL_MADRASAH/SISWA/2026-2027/VII-A/1234567890_NAMA"
     */
    public function ensureFolderPath(string $path): ?string
    {
        if (! $this->folderId) {
            return null;
        }

        $segments = array_values(array_filter(array_map(
            fn ($s) => trim(preg_replace('/[^\w\s\-.()]/u', '', $s)),
            explode('/', $path)
        )));

        if (empty($segments)) {
            return $this->folderId;
        }

        $parentId = $this->folderId;
        $walked = '';
        foreach ($segments as $name) {
            $walked .= '/'.$name;
            if (isset($this->folderCache[$walked])) {
                $parentId = $this->folderCache[$walked];

                continue;
            }
            $parentId = $this->findOrCreateFolder($name, $parentId);
            if (! $parentId) {
                return null;
            }
            $this->folderCache[$walked] = $parentId;
        }

        return $parentId;
    }

    protected function findOrCreateFolder(string $name, string $parentId): ?string
    {
        try {
            $found = $this->service->files->listFiles([
                'q' => sprintf(
                    "mimeType='application/vnd.google-apps.folder' and name='%s' and '%s' in parents and trashed=false",
                    str_replace("'", "\\'", $name),
                    $parentId
                ),
                'fields' => 'files(id)',
                'pageSize' => 1,
            ]);

            $files = $found->getFiles();
            if (! empty($files)) {
                return $files[0]->getId();
            }

            $folder = new Google_Service_Drive_DriveFile;
            $folder->setName($name);
            $folder->setMimeType('application/vnd.google-apps.folder');
            $folder->setParents([$parentId]);

            return $this->service->files->create($folder, ['fields' => 'id'])->getId();
        } catch (\Exception $e) {
            Log::warning('[GDRIVE] Gagal siapkan folder', ['name' => $name, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Tentukan path arsip sesuai blueprint §6 per jenis dokumen.
     * Fallback ke folder akar bila relasi tidak lengkap.
     */
    public function resolveArchivePath(string $type, object $model): string
    {
        $year = str_replace('/', '-', AcademicYear::active()?->year ?? date('Y').'/'.(date('Y') + 1));

        $studentName = function ($student): string {
            if (! $student) {
                return 'TANPA_NAMA';
            }
            $safe = trim(preg_replace('/[^\w\s]/u', '', (string) ($student->name ?? 'TANPA_NAMA')));
            $safe = preg_replace('/\s+/', '_', $safe);

            return substr(($student->nisn ?? 'NONISN').'_'.$safe, 0, 80);
        };

        return match ($type) {
            'ppdb' => sprintf(
                'ARSIP_DIGITAL_MADRASAH/PPDB/%s/%s',
                $year,
                $studentName($model->registration ?? null) !== 'TANPA_NAMA'
                    ? $studentName($model->registration)
                    : ($model->ppdb_registration_id ?? 'ARSIP'),
            ),
            'mutation' => sprintf(
                'ARSIP_DIGITAL_MADRASAH/MUTASI/%s/%s',
                $year,
                $studentName($model->student ?? Student::find($model->student_id ?? null)),
            ),
            'student_document' => sprintf(
                'ARSIP_DIGITAL_MADRASAH/SISWA/%s/%s/%s',
                $year,
                $model->student?->currentCohort?->rombel?->name ?? 'TANPA_ROMBEL',
                $studentName($model->student),
            ),
            'photo' => sprintf(
                'ARSIP_DIGITAL_MADRASAH/SISWA/%s/FOTO/%s',
                $year,
                $studentName($model),
            ),
            default => 'ARSIP_DIGITAL_MADRASAH/LAINNYA',
        };
    }
}
