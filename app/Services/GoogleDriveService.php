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
    protected string $folderId;

    public function __construct()
    {
        $this->client = new Google_Client();
        $this->client->setClientId(env('GDRIVE_CLIENT_ID'));
        $this->client->setClientSecret(env('GDRIVE_CLIENT_SECRET'));
        $this->client->refreshToken(env('GDRIVE_REFRESH_TOKEN'));
        
        $this->service = new Google_Service_Drive($this->client);
        $this->folderId = env('GDRIVE_FOLDER_ID');
    }

    /**
     * Upload a file to Google Drive and return the file ID and Web View Link.
     */
    public function uploadFile(string $filePath, string $mimeType, string $originalName): ?array
    {
        try {
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
