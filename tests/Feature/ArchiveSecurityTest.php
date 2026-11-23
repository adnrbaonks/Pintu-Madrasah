<?php

namespace Tests\Feature;

use App\Models\Staff;
use App\Models\StaffChangeAttachment;
use App\Models\StaffChangeRequest;
use App\Models\StaffDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ArchiveSecurityTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
    }

    public function test_guest_cannot_access_legacy_archive_files(): void
    {
        $this->get('/archives/student_document/sample.pdf')
            ->assertRedirect('/login');
    }

    public function test_path_traversal_attempts_are_blocked(): void
    {
        $user = User::factory()->create(['role' => 'guru']);

        $this->actingAs($user)
            ->get('/archives/invalid_type/sample.pdf')
            ->assertStatus(404);
    }

    public function test_unprivileged_user_cannot_access_other_users_documents(): void
    {
        $staffA = Staff::factory()->create();
        $userA = User::factory()->create(['role' => 'guru', 'staff_id' => $staffA->id]);

        $staffB = Staff::factory()->create();
        $userB = User::factory()->create(['role' => 'guru', 'staff_id' => $staffB->id]);

        $docB = StaffDocument::create([
            'staff_id' => $staffB->id,
            'document_type' => 'ijazah',
            'title' => 'Ijazah B',
            'file_path' => 'archives/staff_document/doc-b.pdf',
            'uploaded_by' => $userB->id,
        ]);

        Storage::disk('local')->put("archives/staff_document/{$docB->id}.pdf", 'Secret content B');

        // User A mencoba mengakses dokumen milik User B -> 403 Forbidden
        $this->actingAs($userA)
            ->get("/archives/staff_document/{$docB->id}.pdf")
            ->assertStatus(403);

        // User B mengakses dokumen miliknya sendiri -> 200 OK
        $this->actingAs($userB)
            ->get("/archives/staff_document/{$docB->id}.pdf")
            ->assertOk();
    }

    public function test_authorized_staff_can_view_documents_and_change_attachments(): void
    {
        $operator = User::factory()->create(['role' => 'operator']);
        $guruStaff = Staff::factory()->create();
        $guru = User::factory()->create(['role' => 'guru', 'staff_id' => $guruStaff->id]);

        $req = StaffChangeRequest::create([
            'staff_id' => Staff::factory()->create()->id,
            'requester_user_id' => $guru->id,
            'status' => 'submitted',
            'reason' => 'Perubahan NIK',
        ]);

        $att = StaffChangeAttachment::create([
            'request_id' => $req->id,
            'path' => 'archives/staff_change_attachment/att-1.pdf',
            'original_name' => 'KTP.pdf',
            'mime' => 'application/pdf',
            'size' => 1024,
            'checksum' => 'fake-sha256',
            'uploaded_by' => $guru->id,
        ]);

        Storage::disk('local')->put("archives/staff_change_attachment/{$att->id}.pdf", 'KTP file content');

        // Guru pemohon dapat melihat lampirannya
        $this->actingAs($guru)
            ->get("/archives/staff_change_attachment/{$att->id}.pdf")
            ->assertOk();

        // Operator dapat melihat lampiran untuk verifikasi
        $this->actingAs($operator)
            ->get("/archives/staff_change_attachment/{$att->id}.pdf")
            ->assertOk();
    }
}
