<?php

namespace App\Jobs;

use App\Models\PpdbDocument;
use App\Models\Student;
use App\Models\StudentDocument;
use App\Models\StudentMutation;
use App\Services\GoogleDriveService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class UploadToGoogleDriveJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $backoff = [30, 60, 120]; // Wait time before retrying

    protected $type;

    protected $modelId;

    /**
     * Create a new job instance.
     */
    public function __construct(string $type, string $modelId)
    {
        $this->type = $type;
        $this->modelId = $modelId;
    }

    /**
     * Execute the job.
     */
    public function handle(GoogleDriveService $driveService): void
    {
        $model = $this->getModel();
        if (! $model) {
            Log::warning("UploadToGoogleDriveJob: Model not found. Type: {$this->type}, ID: {$this->modelId}");

            return;
        }

        $this->updateStatus($model, 'uploading');
        $this->incrementRetries($model);

        $localPath = $this->getLocalPath($model);

        if (! $localPath || ! Storage::disk('local')->exists($localPath)) {
            Log::error("UploadToGoogleDriveJob: Local file not found. Path: {$localPath}");
            $this->updateStatus($model, 'failed');

            return;
        }

        $absolutePath = Storage::disk('local')->path($localPath);
        $fileName = basename($localPath);
        $mimeType = mime_content_type($absolutePath);

        try {
            $folderId = $driveService->ensureFolderPath(
                $driveService->resolveArchivePath($this->type, $model)
            );
            $driveFile = $driveService->uploadFile($absolutePath, $mimeType, $fileName, $folderId);

            if ($driveFile && isset($driveFile['gdrive_file_id'])) {
                $this->updateSuccess($model, $driveFile['gdrive_file_id'], $driveFile['gdrive_view_url']);
            } else {
                throw new \Exception('Google Drive upload failed, invalid response.');
            }
        } catch (Throwable $e) {
            Log::error('UploadToGoogleDriveJob: Upload failed - '.$e->getMessage());
            throw $e; // This will trigger the retry mechanism
        }
    }

    public function failed(Throwable $exception): void
    {
        $model = $this->getModel();
        if ($model) {
            $this->updateStatus($model, 'failed');
            Log::error("UploadToGoogleDriveJob: Job failed completely after retries. Type: {$this->type}, ID: {$this->modelId}. Error: ".$exception->getMessage());
        }
    }

    protected function getModel()
    {
        return match ($this->type) {
            'ppdb' => PpdbDocument::find($this->modelId),
            'mutation' => StudentMutation::find($this->modelId),
            'student_document' => StudentDocument::find($this->modelId),
            'photo' => Student::find($this->modelId),
            default => null,
        };
    }

    protected function getLocalPath($model): ?string
    {
        return match ($this->type) {
            'ppdb' => $model->file_path,
            'mutation' => $model->document_path,
            'student_document' => 'archives/student_document/'.$model->id.'.pdf', // Need to check how StudentDocumentController saves it
            'photo' => $model->profile_photo,
            default => null,
        };
    }

    protected function updateStatus($model, string $status): void
    {
        match ($this->type) {
            'ppdb', 'student_document' => $model->update(['status' => $status]),
            'mutation' => $model->update(['document_status' => $status]),
            'photo' => $model->update(['photo_upload_status' => $status]),
        };
    }

    protected function incrementRetries($model): void
    {
        match ($this->type) {
            'ppdb', 'student_document', 'mutation' => $model->increment('upload_retries'),
            'photo' => $model->increment('photo_upload_retries'),
        };
    }

    protected function updateSuccess($model, string $fileId, string $viewUrl): void
    {
        match ($this->type) {
            'ppdb', 'student_document' => $model->update([
                'gdrive_file_id' => $fileId,
                'gdrive_view_url' => $viewUrl,
                'status' => 'uploaded',
            ]),
            'mutation' => $model->update([
                'gdrive_file_id' => $fileId,
                'gdrive_view_url' => $viewUrl,
                'document_status' => 'uploaded',
            ]),
            'photo' => $model->update([
                'gdrive_photo_id' => $fileId,
                'gdrive_photo_url' => $viewUrl,
                'photo_upload_status' => 'uploaded',
            ]),
        };
    }
}
