<?php

namespace App\Services;

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
        $this->client = new Google_Client();

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
    public function uploadFile(string $filePath, string $mimeType, string $originalName): ?array
    {
        try {
            if (! $this->folderId) {
                Log::warning('[GDRIVE] Folder ID belum dikonfigurasi (GOOGLE_DRIVE_FOLDER_ID)');
                return null;
            }

            $file = new Google_Service_Drive_DriveFile();
            $file->setName($originalName);
            $file->setParents([$this->folderId]);

            $result = $this->service->files->create(
                $file,
                [
                    'data' => file_get_contents($filePath),
                    'mimeType' => $mimeType,
                    'uploadType' => 'multipart',
                    'fields' => 'id, webViewLink'
                ]
            );

            // Set file permissions to 'anyone with link can view'
            $permission = new \Google_Service_Drive_Permission();
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
}
