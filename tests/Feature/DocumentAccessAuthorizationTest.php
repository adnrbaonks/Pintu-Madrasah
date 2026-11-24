<?php

namespace Tests\Feature;

use App\Models\Staff;
use App\Models\StaffChangeAttachment;
use App\Models\StaffChangeRequest;
use App\Models\StaffDocument;
use App\Models\Student;
use App\Models\StudentDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DocumentAccessAuthorizationTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_staff_documents_allow_only_owner_or_view_staff_permission(): void
    {
        $staffA = Staff::factory()->create();
        $staffB = Staff::factory()->create();

        $owner = User::factory()->create(['role' => 'guru', 'staff_id' => $staffA->id]);
        $otherGuru = User::factory()->create(['role' => 'guru', 'staff_id' => $staffB->id]);
        $staf = User::factory()->create(['role' => 'staf']);

        $documentId = $this->uuid();
        $document = StaffDocument::create([
            'id' => $documentId,
            'staff_id' => $staffA->id,
            'document_type' => 'ktp',
            'title' => 'KTP Guru A',
            'file_path' => "archives/staff_document/{$documentId}.pdf",
            'status' => 'uploaded',
        ]);
        Storage::disk('local')->put($document->file_path, 'ktp-content');

        $filename = basename($document->file_path);

        $this->actingAs($owner)->get("/archives/staff_document/{$filename}")->assertOk();
        $this->actingAs($otherGuru)->get("/archives/staff_document/{$filename}")->assertForbidden();
        $this->actingAs($staf)->get("/archives/staff_document/{$filename}")->assertOk();

        // Pencabutan permission granular langsung mencabut akses meski role staf tetap melekat.
        Role::findByName('staf')->revokePermissionTo('view_staff');
        $this->actingAs($staf->fresh())->get("/archives/staff_document/{$filename}")->assertForbidden();
    }

    public function test_staff_change_attachments_are_owner_or_reviewer_only(): void
    {
        $staff = Staff::factory()->create();
        $requester = User::factory()->create(['role' => 'guru', 'staff_id' => $staff->id]);
        $otherGuru = User::factory()->create(['role' => 'guru']);
        $kaurTu = User::factory()->create(['role' => 'kaur_tu']);
        $staf = User::factory()->create(['role' => 'staf']);

        $changeRequest = StaffChangeRequest::create([
            'staff_id' => $staff->id,
            'requester_user_id' => $requester->id,
            'status' => StaffChangeRequest::STATUS_SUBMITTED,
            'reason' => 'Uji akses lampiran',
            'created_by' => $requester->id,
        ]);

        $attachmentId = $this->uuid();
        $path = "archives/staff_change_attachment/{$attachmentId}.pdf";

        StaffChangeAttachment::create([
            'id' => $attachmentId,
            'request_id' => $changeRequest->id,
            'path' => $path,
            'original_name' => 'bukti.pdf',
            'mime' => 'application/pdf',
            'size' => 10,
            'uploaded_by' => $requester->id,
        ]);
        Storage::disk('local')->put($path, 'attachment-content');

        $this->assertSame($requester->id, StaffChangeAttachment::findOrFail($attachmentId)->changeRequest?->requester_user_id);

        $this->actingAs($requester)->get("/archives/staff_change_attachment/{$attachmentId}.pdf")->assertOk();
        $this->actingAs($otherGuru)->get("/archives/staff_change_attachment/{$attachmentId}.pdf")->assertForbidden();
        $this->actingAs($kaurTu)->get("/archives/staff_change_attachment/{$attachmentId}.pdf")->assertOk();
        $this->actingAs($staf)->get("/archives/staff_change_attachment/{$attachmentId}.pdf")->assertForbidden();
    }

    public function test_student_documents_require_specific_document_permission_not_generic_student_view(): void
    {
        $student = Student::factory()->create();
        $document = StudentDocument::create([
            'student_id' => $student->id,
            'document_type' => 'kartu_keluarga',
            'status' => 'uploaded',
        ]);
        $path = "archives/student_document/{$document->id}.pdf";
        Storage::disk('local')->put($path, 'kk-content');

        $guru = User::factory()->create(['role' => 'guru']);
        $staf = User::factory()->create(['role' => 'staf']);
        $operator = User::factory()->create(['role' => 'operator']);

        // Guru memiliki view_students, tetapi tidak boleh membaca dokumen identitas siswa.
        $this->assertTrue($guru->can('view_students'));
        $this->actingAs($guru)->get("/archives/student_document/{$document->id}.pdf")->assertForbidden();

        $this->actingAs($staf)->get("/archives/student_document/{$document->id}.pdf")->assertOk();
        $this->actingAs($operator)->get("/archives/student_document/{$document->id}.pdf")->assertOk();

        Role::findByName('staf')->revokePermissionTo('view_student_documents');
        $this->actingAs($staf->fresh())->get("/archives/student_document/{$document->id}.pdf")->assertForbidden();
    }

    public function test_ppdb_and_mutation_documents_use_document_specific_permissions(): void
    {
        Storage::disk('local')->put('archives/ppdb/berkas.pdf', 'ppdb-content');
        Storage::disk('local')->put('archives/mutation/surat.pdf', 'mutation-content');

        $guru = User::factory()->create(['role' => 'guru']);
        $staf = User::factory()->create(['role' => 'staf']);

        $this->actingAs($guru)->get('/archives/ppdb/berkas.pdf')->assertForbidden();
        $this->actingAs($guru)->get('/archives/mutation/surat.pdf')->assertForbidden();

        $this->actingAs($staf)->get('/archives/ppdb/berkas.pdf')->assertOk();
        $this->actingAs($staf)->get('/archives/mutation/surat.pdf')->assertOk();

        $stafRole = Role::findByName('staf');
        $stafRole->revokePermissionTo('view_ppdb_documents');
        $stafRole->revokePermissionTo('view_mutation_documents');

        $this->actingAs($staf->fresh())
            ->get('/archives/ppdb/berkas.pdf')
            ->assertForbidden();
        $this->actingAs($staf->fresh())
            ->get('/archives/mutation/surat.pdf')
            ->assertForbidden();
    }

    public function test_document_retry_is_gated_by_document_specific_permission(): void
    {
        $staf = User::factory()->create(['role' => 'staf']);

        // Staf berwenang lolos gerbang otorisasi, lalu model tidak ditemukan.
        $this->actingAs($staf)
            ->post('/documents/ppdb/'.$this->uuid().'/retry')
            ->assertNotFound();

        Role::findByName('staf')->revokePermissionTo('view_ppdb_documents');

        $this->actingAs($staf->fresh())
            ->post('/documents/ppdb/'.$this->uuid().'/retry')
            ->assertForbidden();
    }

    private function uuid(): string
    {
        return (string) Str::uuid();
    }
}
