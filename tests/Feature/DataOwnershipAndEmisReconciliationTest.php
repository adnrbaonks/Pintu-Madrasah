<?php

namespace Tests\Feature;

use App\Jobs\CommitEmisStagingJob;
use App\Models\ActivityLog;
use App\Models\EmisImportBatch;
use App\Models\EmisReconciliationItem;
use App\Models\EmisStaging;
use App\Models\EmisUpdateTask;
use App\Models\Staff;
use App\Models\StaffChangeRequest;
use App\Models\Student;
use App\Models\StudentDocument;
use App\Models\User;
use App\Services\DataOwnershipService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DataOwnershipAndEmisReconciliationTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_data_ownership_matrix_makes_pintu_ssot_for_student_and_staff_master_data(): void
    {
        $service = app(DataOwnershipService::class);

        foreach (['nisn', 'nik', 'no_kk', 'name', 'gender', 'birth_place', 'birth_date', 'address'] as $studentField) {
            $this->assertTrue($service->isOwnedByPintu('student', $studentField), "Field student.{$studentField} harus dimiliki PINTU.");
            $this->assertTrue($service->isEditable('student', $studentField), "Field student.{$studentField} harus dapat dikelola operator.");
        }

        // Referensi teknis eksternal tetap read-only
        $this->assertFalse($service->isOwnedByPintu('student', 'emis_student_id'));
        $this->assertFalse($service->isEditable('student', 'emis_student_id'));
        $this->assertFalse($service->isOwnedByPintu('staff', 'emis_gtk_id'));
        $this->assertFalse($service->isEditable('staff', 'emis_gtk_id'));

        // Identitas penting GTK dimiliki PINTU, namun melalui mekanisme approval_required (bukan direct edit bebas)
        foreach (['nik', 'nip', 'nuptk', 'npk', 'gender', 'birth_place', 'birth_date', 'civil_rank'] as $staffField) {
            $this->assertTrue($service->isOwnedByPintu('staff', $staffField), "Field staff.{$staffField} harus dimiliki PINTU.");
            $this->assertFalse($service->isEditable('staff', $staffField), "Field staff.{$staffField} wajib melalui alur persetujuan.");
        }

        // Data transaksi satelit tetap mengikuti kepemilikan aplikasi satelit
        $this->assertSame('rdm', config('data_ownership.academic.student_grades.owner_system'));
    }

    public function test_operator_manages_student_identity_with_document_validation_audit_trail_and_outbound_emis_task(): void
    {
        $operator = User::factory()->create(['role' => 'operator']);
        $guru = User::factory()->create(['role' => 'guru']);

        $student = Student::factory()->create([
            'nisn' => '0012345678',
            'nik' => '3201012010010001',
            'name' => 'Ahmad Fauzi',
            'gender' => 'L',
            'birth_place' => 'Sumedang',
            'birth_date' => '2012-05-10',
            'address' => 'Jl. Lama No. 1',
            'is_locked' => true,
        ]);

        // Guru tidak berwenang mengubah data master siswa
        $this->actingAs($guru)
            ->put("/students/{$student->id}", [
                'nisn' => '0012345678',
                'nik' => '3201012010010001',
                'name' => 'Ahmad Fauzi Diubah Guru',
                'gender' => 'L',
                'status' => $student->status,
            ])
            ->assertForbidden();

        // Perubahan identitas sensitif tanpa dokumen pendukung harus ditolak
        $this->actingAs($operator)
            ->from("/students/{$student->id}/edit")
            ->put("/students/{$student->id}", [
                'nisn' => '0012345699',
                'nik' => '3201012010010099',
                'name' => 'Ahmad Fauzi Resmi',
                'gender' => 'L',
                'birth_place' => 'Bandung',
                'birth_date' => '2012-05-11',
                'status' => $student->status,
            ])
            ->assertRedirect("/students/{$student->id}/edit")
            ->assertSessionHasErrors('identity_verification');

        $this->assertSame('Ahmad Fauzi', $student->fresh()->name);

        // Unggah dokumen pendukung siswa (KK/Akta)
        StudentDocument::create([
            'student_id' => $student->id,
            'document_type' => 'kartu_keluarga',
            'file_path' => 'archives/student_document/kk-ahmad.pdf',
            'status' => 'verified',
        ]);

        // Siswa terkunci (is_locked=true) tetap wajib menyertakan alasan perubahan identitas
        $this->actingAs($operator)
            ->from("/students/{$student->id}/edit")
            ->put("/students/{$student->id}", [
                'nisn' => '0012345699',
                'nik' => '3201012010010099',
                'name' => 'Ahmad Fauzi Resmi',
                'gender' => 'L',
                'birth_place' => 'Bandung',
                'birth_date' => '2012-05-11',
                'status' => $student->status,
            ])
            ->assertRedirect("/students/{$student->id}/edit")
            ->assertSessionHasErrors('identity_change_reason');

        // Dengan dokumen pendukung dan alasan resmi, operator berhasil memperbarui master PINTU
        $this->actingAs($operator)
            ->put("/students/{$student->id}", [
                'nisn' => '0012345699',
                'nik' => '3201012010010099',
                'name' => 'Ahmad Fauzi Resmi',
                'gender' => 'L',
                'birth_place' => 'Bandung',
                'birth_date' => '2012-05-11',
                'address' => 'Jl. Baru No. 99',
                'status' => $student->status,
                'identity_change_reason' => 'Perbaikan sesuai Akta Kelahiran dan KK terbaru.',
            ])
            ->assertRedirect();

        $student->refresh();
        $this->assertSame('0012345699', $student->nisn);
        $this->assertSame('3201012010010099', $student->nik);
        $this->assertSame('AHMAD FAUZI RESMI', $student->name);
        $this->assertSame('Bandung', $student->birth_place);

        // Tercatat dalam audit trail perubahan identitas
        $audit = ActivityLog::where('action', 'student_identity_updated')
            ->where('model_id', $student->id)
            ->first();
        $this->assertNotNull($audit);
        $this->assertSame('Ahmad Fauzi', $audit->before_payload['name'] ?? null);
        $this->assertSame('AHMAD FAUZI RESMI', $audit->after_payload['name'] ?? null);

        // Menghasilkan tugas pembaruan EMIS outbound untuk operator EMIS
        $task = EmisUpdateTask::where('entity_type', 'student')
            ->where('entity_id', $student->id)
            ->first();
        $this->assertNotNull($task);
        $this->assertSame(EmisUpdateTask::STATUS_PENDING, $task->status);
        $this->assertArrayHasKey('nisn', $task->changed_fields);
        $this->assertArrayHasKey('nik', $task->changed_fields);
        $this->assertArrayHasKey('name', $task->changed_fields);
    }

    public function test_gtk_high_risk_identity_change_requires_document_and_follows_tu_headmaster_and_emis_operator_workflow(): void
    {
        $staff = Staff::factory()->create([
            'nik' => '3211000000000001',
            'nuptk' => '1111222233334444',
            'full_name_with_titles' => 'Budi Santoso, S.Pd.',
            'birth_place' => 'Garut',
        ]);

        $gtkUser = User::factory()->create(['role' => 'guru', 'staff_id' => $staff->id]);
        $kaurTu = User::factory()->create(['role' => 'kaur_tu']);
        $kamad = User::factory()->create(['role' => 'kepala_madrasah']);
        $techAdmin = User::factory()->create(['role' => 'admin']);
        $operator = User::factory()->create(['role' => 'operator']);

        // 1. Pengajuan perubahan identitas penting (NIK / NUPTK / nama) tanpa dokumen pendukung ditolak saat submit
        $this->actingAs($gtkUser)
            ->post('/profil-gtk/pengajuan', [
                'reason' => 'Perbaikan NIK dan NUPTK sesuai KK dan SK Dirjen',
                'changes' => [
                    'nik' => '3211000000000099',
                    'nuptk' => '9999888877776666',
                ],
                'submit_now' => true,
            ])
            ->assertSessionHasErrors('attachments');

        // Draft tersimpan tetapi belum berstatus submitted
        $changeRequest = StaffChangeRequest::where('staff_id', $staff->id)->firstOrFail();
        $this->assertSame(StaffChangeRequest::STATUS_DRAFT, $changeRequest->status);

        // 2. Perbarui draft dengan dokumen pendukung lalu submit
        $this->actingAs($gtkUser)
            ->put("/profil-gtk/pengajuan/{$changeRequest->id}", [
                'reason' => 'Perbaikan NIK dan NUPTK sesuai KK dan SK Dirjen',
                'changes' => [
                    'nik' => '3211000000000099',
                    'nuptk' => '9999888877776666',
                ],
                'attachments' => [
                    UploadedFile::fake()->create('kk-dan-sk.pdf', 200, 'application/pdf'),
                ],
                'submit_now' => true,
            ])
            ->assertRedirect();

        $changeRequest->refresh();
        $this->assertSame(StaffChangeRequest::STATUS_UNDER_TU_REVIEW, $changeRequest->status);

        // Master GTK belum berubah sebelum disetujui
        $this->assertSame('3211000000000001', $staff->fresh()->nik);

        // 3. Admin teknis tidak memiliki kewenangan verifikasi maupun persetujuan administratif
        $this->actingAs($techAdmin)
            ->post("/verifikasi-gtk/{$changeRequest->id}/verify")
            ->assertForbidden();
        $this->actingAs($techAdmin)
            ->post("/persetujuan-gtk/{$changeRequest->id}/approve")
            ->assertForbidden();

        // 4. Kaur TU memverifikasi
        $this->actingAs($kaurTu)
            ->post("/verifikasi-gtk/{$changeRequest->id}/verify", [
                'notes' => 'Dokumen KK dan SK NUPTK sesuai.',
            ])
            ->assertRedirect();

        $this->assertSame(StaffChangeRequest::STATUS_PENDING_HEAD_APPROVAL, $changeRequest->fresh()->status);

        // 5. Kepala Madrasah menyetujui -> diterapkan ke master PINTU dan tercatat di tugas pembaruan EMIS
        $this->actingAs($kamad)
            ->post("/persetujuan-gtk/{$changeRequest->id}/approve", [
                'notes' => 'Disetujui untuk diperbarui di master PINTU dan EMIS.',
            ])
            ->assertRedirect();

        $staff->refresh();
        $changeRequest->refresh();

        $this->assertSame(StaffChangeRequest::STATUS_COMPLETED, $changeRequest->status);
        $this->assertSame('3211000000000099', $staff->nik);
        $this->assertSame('9999888877776666', $staff->nuptk);

        $task = EmisUpdateTask::where('entity_type', 'staff')
            ->where('entity_id', $staff->id)
            ->where('source_id', $changeRequest->id)
            ->firstOrFail();

        $this->assertSame(EmisUpdateTask::STATUS_PENDING, $task->status);
        $this->assertSame('3211000000000099', $task->changed_fields['nik']['new_value']);
        $this->assertSame('9999888877776666', $task->changed_fields['nuptk']['new_value']);

        // 6. Operator EMIS mencatat status pembaruan EMIS tanpa mengubah master PINTU
        $this->actingAs($operator)
            ->patchJson("/api/emis-gtk/update-tasks/{$task->id}", [
                'status' => EmisUpdateTask::STATUS_COMPLETED,
                'operator_notes' => 'Telah diperbarui pada portal EMIS GTK Kemenag.',
                'confirmation_reference' => 'EMIS-GTK-20261127-01',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', EmisUpdateTask::STATUS_COMPLETED)
            ->assertJsonPath('data.confirmation_reference', 'EMIS-GTK-20261127-01');

        $task->refresh();
        $this->assertSame(EmisUpdateTask::STATUS_COMPLETED, $task->status);
        $this->assertNotNull($task->external_updated_at);
        $this->assertSame('3211000000000099', $staff->fresh()->nik);
    }

    public function test_emis_reconciliation_classifies_same_different_pintu_only_emis_only_and_needs_review_without_mutating_pintu_master(): void
    {
        $operator = User::factory()->create(['role' => 'operator']);

        // 1. Siswa identik antara PINTU dan EMIS
        $studentSame = Student::factory()->create([
            'nisn' => '1000000001',
            'nik' => '3201000000000001',
            'name' => 'Siswa Identik',
            'gender' => 'L',
            'birth_place' => 'Sumedang',
            'birth_date' => '2012-01-01',
            'mother_name' => 'Ibu Identik',
            'father_name' => 'Ayah Identik',
            'address' => 'Alamat Identik',
        ]);

        // 2. Siswa berbeda antara PINTU (sudah diperbarui operator) dan EMIS (masih data lama)
        $studentDiff = Student::factory()->create([
            'nisn' => '1000000002',
            'nik' => '3201000000000002',
            'name' => 'Siswa Master PINTU Resmi',
            'gender' => 'P',
            'birth_place' => 'Bandung',
            'birth_date' => '2012-02-02',
        ]);

        // 3. Siswa hanya ada di PINTU
        $studentPintuOnly = Student::factory()->create([
            'nisn' => '1000000003',
            'nik' => '3201000000000003',
            'name' => 'Siswa Khusus PINTU',
        ]);

        // Staging untuk siswa identik
        EmisStaging::create([
            'source' => 'bulk',
            'nisn' => '1000000001',
            'nik' => '3201000000000001',
            'name' => 'Siswa Identik',
            'gender' => 'L',
            'status' => 'pending',
            'raw_data' => [
                'birth_place' => 'Sumedang',
                'birth_date' => '2012-01-01',
                'mother_name' => 'Ibu Identik',
                'father_name' => 'Ayah Identik',
                'address' => 'Alamat Identik',
            ],
        ]);

        // Staging untuk siswa berbeda (di EMIS nama masih lama)
        EmisStaging::create([
            'source' => 'bulk',
            'nisn' => '1000000002',
            'nik' => '3201000000000002',
            'name' => 'Siswa Versi EMIS Lama',
            'gender' => 'P',
            'status' => 'pending',
            'raw_data' => [
                'birth_place' => 'Garut',
                'birth_date' => '2012-02-02',
            ],
        ]);

        // Staging hanya ada di EMIS
        EmisStaging::create([
            'source' => 'bulk',
            'nisn' => '1000000099',
            'nik' => '3201000000000099',
            'name' => 'Siswa Hanya Di EMIS',
            'gender' => 'L',
            'status' => 'pending',
            'raw_data' => [],
        ]);

        // Staging konflik identitas (NISN milik studentSame, NIK milik studentDiff) -> needs_review
        EmisStaging::create([
            'source' => 'bulk',
            'nisn' => '1000000001',
            'nik' => '3201000000000002',
            'name' => 'Siswa Konflik Identitas',
            'gender' => 'L',
            'status' => 'pending',
            'raw_data' => [],
        ]);

        $response = $this->actingAs($operator)
            ->postJson('/api/emis/reconcile', [
                'notes' => 'Uji rekonsiliasi semester ganjil',
            ])
            ->assertOk();

        $batchId = $response->json('data.id');
        $batch = EmisImportBatch::findOrFail($batchId);

        $this->assertSame(EmisImportBatch::PROCESS_RECONCILIATION, $batch->process_type);
        $this->assertSame(1, $batch->matched_identical_count);
        $this->assertSame(1, $batch->difference_count);
        $this->assertSame(1, $batch->pintu_only_count);
        $this->assertSame(1, $batch->emis_only_count);
        $this->assertSame(1, $batch->needs_review_count);

        // Master PINTU sama sekali tidak berubah setelah rekonsiliasi
        $studentDiff->refresh();
        $this->assertSame('Siswa Master PINTU Resmi', $studentDiff->name);
        $this->assertSame('Bandung', $studentDiff->birth_place);

        // Detail perbedaan tercatat lengkap dan dapat ditelusuri
        $diffItem = EmisReconciliationItem::where('batch_id', $batch->id)
            ->where('entity_id', $studentDiff->id)
            ->where('classification', EmisReconciliationItem::CLASS_DIFFERENT)
            ->firstOrFail();

        $this->assertSame('Siswa Master PINTU Resmi', $diffItem->differences['name']['pintu_value']);
        $this->assertSame('Siswa Versi EMIS Lama', $diffItem->differences['name']['emis_value']);
        $this->assertSame('Bandung', $diffItem->differences['birth_place']['pintu_value']);
        $this->assertSame('Garut', $diffItem->differences['birth_place']['emis_value']);
    }

    public function test_emis_reimport_does_not_overwrite_pintu_master_in_production_and_requires_confirmation_for_overwrite(): void
    {
        $operator = User::factory()->create(['role' => 'operator']);

        $student = Student::factory()->create([
            'emis_student_id' => 'EMIS-STU-001',
            'nisn' => '2000000001',
            'nik' => '3201000000000101',
            'name' => 'Nama Resmi Di PINTU',
            'birth_place' => 'Sumedang',
        ]);

        EmisStaging::create([
            'source' => 'bulk',
            'nisn' => '2000000001',
            'nik' => '3201000000000101',
            'name' => 'Nama Lama Dari EMIS',
            'status' => 'pending',
            'raw_data' => [
                'birth_place' => 'Cirebon',
            ],
        ]);

        // Permintaan commit dengan allow_overwrite=true tetapi tanpa overwrite_confirmed=true harus ditolak 422
        $this->actingAs($operator)
            ->postJson('/api/emis/commit', [
                'allow_overwrite' => true,
                'overwrite_confirmed' => false,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'OVERWRITE_CONFIRMATION_REQUIRED');

        // Jalankan job import default (mode produksi non-destructive)
        (new CommitEmisStagingJob(actorId: $operator->id))->handle();

        // Master PINTU tidak tertimpa dan tidak terjadi duplikasi siswa
        $student->refresh();
        $this->assertSame(1, Student::count());
        $this->assertSame('Nama Resmi Di PINTU', $student->name);
        $this->assertSame('Sumedang', $student->birth_place);

        // Metadata batch import awal dan temuan perbedaan tercatat
        $batch = EmisImportBatch::where('entity_type', 'student')
            ->where('process_type', EmisImportBatch::PROCESS_INITIAL_IMPORT)
            ->latest()
            ->firstOrFail();

        $this->assertSame(0, $batch->created_count);
        $this->assertSame(1, $batch->skipped_existing_count);
        $this->assertSame(1, $batch->difference_count);
    }
}
