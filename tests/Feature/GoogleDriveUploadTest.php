<?php

namespace Tests\Feature;

use App\Jobs\UploadToGoogleDriveJob;
use App\Models\PpdbDocument;
use App\Models\PpdbRegistration;
use App\Models\Student;
use App\Models\StudentDocument;
use App\Models\StudentMutation;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Throwable;

class GoogleDriveUploadTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_student_document_upload_uses_original_extension_and_saves_columns(): void
    {
        Storage::fake('local');

        $document = $this->studentDocument();
        Storage::disk('local')->put($this->archivePath($document, 'png'), $this->pngBytes());

        $drive = $this->fakeDrive();

        (new UploadToGoogleDriveJob('student_document', $document->id))->handle($drive);

        $document->refresh();

        $this->assertSame('uploaded', $document->status);
        $this->assertSame('file-1', $document->gdrive_file_id);
        $this->assertSame('https://drive.test/file-1', $document->gdrive_view_url);
        $this->assertSame(1, $document->upload_retries);
        $this->assertCount(1, $drive->uploads);
        $this->assertSame($document->id.'.png', $drive->uploads[0]['originalName']);
        $this->assertSame('image/png', $drive->uploads[0]['mimeType']);
    }

    public function test_failed_student_document_upload_marks_status_failed(): void
    {
        Storage::fake('local');

        $document = $this->studentDocument();
        Storage::disk('local')->put($this->archivePath($document, 'pdf'), '%PDF-1.4 contoh');

        $job = new UploadToGoogleDriveJob('student_document', $document->id);

        try {
            $job->handle($this->fakeDrive(shouldFail: true));
            $this->fail('Upload seharusnya melempar exception agar retry berjalan.');
        } catch (Throwable $e) {
            $this->assertStringContainsString('invalid response', $e->getMessage());
        }

        $job->failed(new \Exception('Gagal setelah retry'));

        $this->assertSame('failed', $document->refresh()->status);
    }

    public function test_photo_upload_saves_columns(): void
    {
        Storage::fake('local');

        $student = Student::factory()->create([
            'profile_photo' => 'photos/profil.jpg',
        ]);
        Storage::disk('local')->put('photos/profil.jpg', $this->pngBytes());

        (new UploadToGoogleDriveJob('photo', $student->id))->handle($this->fakeDrive());

        $student->refresh();

        $this->assertSame('uploaded', $student->photo_upload_status);
        $this->assertSame('file-1', $student->gdrive_photo_id);
        $this->assertSame('https://drive.test/file-1', $student->gdrive_photo_url);
        $this->assertSame(1, $student->photo_upload_retries);
    }

    public function test_ppdb_document_upload_saves_columns(): void
    {
        Storage::fake('local');

        $registration = PpdbRegistration::factory()->create();
        $document = PpdbDocument::create([
            'ppdb_registration_id' => $registration->id,
            'document_type' => 'ijazah',
            'file_path' => 'ppdb/'.$registration->id.'.pdf',
        ]);
        Storage::disk('local')->put($document->file_path, '%PDF-1.4 contoh');

        (new UploadToGoogleDriveJob('ppdb', $document->id))->handle($this->fakeDrive());

        $document->refresh();

        $this->assertSame('uploaded', $document->status);
        $this->assertSame('file-1', $document->gdrive_file_id);
        $this->assertSame(1, $document->upload_retries);
    }

    public function test_student_mutation_upload_saves_columns(): void
    {
        Storage::fake('local');

        $student = Student::factory()->create();
        $mutation = StudentMutation::create([
            'student_id' => $student->id,
            'type' => 'out',
            'document_path' => 'mutations/'.$student->id.'.pdf',
        ]);
        Storage::disk('local')->put($mutation->document_path, '%PDF-1.4 contoh');

        (new UploadToGoogleDriveJob('mutation', (string) $mutation->id))->handle($this->fakeDrive());

        $mutation->refresh();

        $this->assertSame('uploaded', $mutation->document_status);
        $this->assertSame('file-1', $mutation->gdrive_file_id);
        $this->assertSame(1, $mutation->upload_retries);
    }

    private function studentDocument(): StudentDocument
    {
        return StudentDocument::create([
            'student_id' => Student::factory()->create()->id,
            'document_type' => 'akta_kelahiran',
            'status' => 'pending',
        ]);
    }

    private function archivePath(StudentDocument $document, string $extension): string
    {
        return 'archives/student_document/'.$document->id.'.'.$extension;
    }

    private function fakeDrive(bool $shouldFail = false): GoogleDriveService
    {
        return new class($shouldFail) extends GoogleDriveService
        {
            /** @var array<int, array<string, mixed>> */
            public array $uploads = [];

            public function __construct(public bool $shouldFail = false) {}

            public function ensureFolderPath(string $path): ?string
            {
                return 'folder-id';
            }

            public function resolveArchivePath(string $type, object $model): string
            {
                return 'ARSIP/TEST';
            }

            public function uploadFile(string $filePath, string $mimeType, string $originalName, ?string $folderId = null): ?array
            {
                $this->uploads[] = compact('filePath', 'mimeType', 'originalName', 'folderId');

                return $this->shouldFail ? null : [
                    'gdrive_file_id' => 'file-1',
                    'gdrive_view_url' => 'https://drive.test/file-1',
                ];
            }
        };
    }

    private function pngBytes(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Zl1sAAAAASUVORK5CYII='
        );
    }
}
