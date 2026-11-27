<?php

namespace Tests\Feature;

use App\Jobs\CommitEmisStagingJob;
use App\Models\AcademicYear;
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
use App\Services\EmisPiiSanitizer;
use App\Services\IntegrationTokenService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
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

        // Semua atribut substantif & identitas GTK dimiliki PINTU, namun melalui mekanisme approval_required (bukan direct edit bebas)
        foreach ([
            'nik', 'nip', 'nuptk', 'npk', 'gender', 'birth_place', 'birth_date', 'civil_rank',
            'full_name_with_titles', 'first_title', 'last_title', 'phone', 'email',
            'employment_status', 'functional_position', 'highest_education',
        ] as $staffField) {
            $this->assertTrue($service->isOwnedByPintu('staff', $staffField), "Field staff.{$staffField} harus dimiliki PINTU.");
            $this->assertFalse($service->isEditable('staff', $staffField), "Field staff.{$staffField} wajib melalui alur persetujuan (approval_required=true).");
        }

        // Hanya field administratif non-substantif (is_active) yang direct editable tanpa approval berjenjang
        $this->assertTrue($service->isEditable('staff', 'is_active'));

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

        // Unggah dokumen pendukung siswa dengan status masih uploaded (belum diverifikasi) -> harus tetap ditolak (P2.4)
        $doc = StudentDocument::create([
            'student_id' => $student->id,
            'document_type' => 'kartu_keluarga',
            'file_path' => 'archives/student_document/kk-ahmad.pdf',
            'status' => 'uploaded',
        ]);

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
                'identity_change_reason' => 'Perbaikan sesuai KK.',
            ])
            ->assertRedirect("/students/{$student->id}/edit")
            ->assertSessionHasErrors('identity_verification');

        // Setelah dokumen berstatus verified, validasi dokumen lulus
        $doc->update(['status' => 'verified']);

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
            'mother_name' => null,
        ]);

        $staging = EmisStaging::create([
            'source' => 'bulk',
            'nisn' => '2000000001',
            'nik' => '3201000000000101',
            'name' => 'Nama Lama Dari EMIS',
            'status' => 'pending',
            'raw_data' => [
                'birth_place' => 'Cirebon',
                'mother_name' => 'Ibu Dari EMIS',
            ],
        ]);

        // 1. Mode PRODUCTION (default): endpoint commit wajib ditolak 403 bahkan dengan allow_overwrite=true
        config(['data_ownership.lifecycle_mode' => 'production']);

        $this->actingAs($operator)
            ->postJson('/api/emis/commit', [
                'allow_overwrite' => true,
                'overwrite_confirmed' => true,
            ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'EMIS_COMMIT_DISABLED_IN_PRODUCTION');

        // Job commit yang dipanggil langsung di mode production juga tidak boleh membuat/mengisi/menimpa data
        (new CommitEmisStagingJob(actorId: $operator->id))->handle();
        $student->refresh();
        $this->assertSame('Nama Resmi Di PINTU', $student->name);
        $this->assertSame('Sumedang', $student->birth_place);
        $this->assertNull($student->mother_name);

        // Endpoint commitEmisDetail pada StudentController juga ditolak di mode production
        $this->actingAs($operator)
            ->from("/students/{$student->id}/edit")
            ->post("/students/{$student->id}/commit-emis-detail", [
                'mother_name' => 'Ibu Dari EMIS',
            ])
            ->assertRedirect("/students/{$student->id}/edit")
            ->assertSessionHasErrors('emis_sync');
        $this->assertNull($student->fresh()->mother_name);

        // 2. Mode MIGRATION: allow_overwrite=true tanpa overwrite_confirmed=true wajib ditolak 422
        config(['data_ownership.lifecycle_mode' => 'migration']);

        $this->actingAs($operator)
            ->postJson('/api/emis/commit', [
                'allow_overwrite' => true,
                'overwrite_confirmed' => false,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'OVERWRITE_CONFIRMATION_REQUIRED');

        // Jalankan job import default di mode migrasi (non-destructive: mengisi mother_name yang kosong, menjaga name/birth_place yang sudah ada)
        (new CommitEmisStagingJob(actorId: $operator->id))->handle();

        $student->refresh();
        $staging->refresh();
        $this->assertSame(1, Student::count());
        $this->assertSame('Nama Resmi Di PINTU', $student->name);
        $this->assertSame('Sumedang', $student->birth_place);
        $this->assertSame('Ibu Dari EMIS', $student->mother_name);

        // Baris staging tetap dipreservasi dengan status committed (P2.7)
        $this->assertSame('committed', $staging->status);

        $batch = EmisImportBatch::where('entity_type', 'student')
            ->where('process_type', EmisImportBatch::PROCESS_INITIAL_IMPORT)
            ->latest()
            ->firstOrFail();

        $this->assertSame(0, $batch->created_count);
        $this->assertSame(1, $batch->skipped_existing_count);
        $this->assertSame(1, $batch->difference_count);

        $reconcileItem = EmisReconciliationItem::where('batch_id', $batch->id)->firstOrFail();
        $this->assertNotEmpty($reconcileItem->pintu_snapshot);
        $this->assertNotEmpty($reconcileItem->emis_snapshot);
    }

    public function test_production_break_glass_and_integration_token_restrictions_on_emis_commit(): void
    {
        config([
            'data_ownership.lifecycle_mode' => 'production',
            'data_ownership.break_glass.enabled' => false,
        ]);

        $operator = User::factory()->create(['role' => 'operator']);
        $studentToken = app(IntegrationTokenService::class)->issue($operator, 'emis_siswa', 'Token Siswa');

        // 1. Integration token tidak pernah boleh memicu commit (bahkan di mode migration atau dengan break_glass)
        config(['data_ownership.lifecycle_mode' => 'migration']);
        $this->postJson('/api/emis/commit', [
            'break_glass' => true,
            'break_glass_confirmed' => true,
            'break_glass_reason' => 'Percobaan bypass lewat integration token.',
        ], [
            'X-Integration-Token' => $studentToken['plain_token'],
        ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'INTEGRATION_TOKEN_COMMIT_FORBIDDEN');

        // 2. Di mode production dengan break_glass.enabled=false -> ditolak 403 BREAK_GLASS_DISABLED
        config(['data_ownership.lifecycle_mode' => 'production']);
        $this->actingAs($operator)
            ->postJson('/api/emis/commit', [
                'break_glass' => true,
                'break_glass_confirmed' => true,
                'break_glass_reason' => 'Migrasi darurat data awal siswa madrasah.',
            ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'BREAK_GLASS_DISABLED');

        // 3. Aktifkan break_glass di config, tetapi user belum punya permission break_glass_emis_import -> 403
        config(['data_ownership.break_glass.enabled' => true]);
        $this->actingAs($operator)
            ->postJson('/api/emis/commit', [
                'break_glass' => true,
                'break_glass_confirmed' => true,
                'break_glass_reason' => 'Migrasi darurat data awal siswa madrasah.',
            ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN_BREAK_GLASS_PERMISSION');

        // 4. Berikan permission break_glass_emis_import, tetapi alasan kosong/pendek -> 422
        $operator->givePermissionTo('break_glass_emis_import');
        $this->actingAs($operator)
            ->postJson('/api/emis/commit', [
                'break_glass' => true,
                'break_glass_confirmed' => true,
                'break_glass_reason' => 'Terlalu pendek',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'BREAK_GLASS_REASON_REQUIRED');

        // 5. Dengan permission, konfirmasi, dan alasan valid -> diizinkan dan tercatat di ActivityLog
        AcademicYear::create([
            'year' => '2026/2027',
            'semester' => 'Ganjil',
            'is_active' => true,
        ]);

        EmisStaging::create([
            'source' => 'bulk',
            'nisn' => '3000000001',
            'nik' => '3201000000000301',
            'name' => 'Siswa Break Glass',
            'gender' => 'L',
            'status' => 'pending',
            'raw_data' => [],
        ]);

        $this->actingAs($operator)
            ->postJson('/api/emis/commit', [
                'break_glass' => true,
                'break_glass_confirmed' => true,
                'break_glass_reason' => 'Migrasi darurat batch siswa pindahan awal tahun ajaran.',
            ])
            ->assertOk()
            ->assertJsonPath('break_glass', true);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'emis_break_glass_commit_authorized',
            'user_id' => $operator->id,
        ]);
    }

    public function test_emis_student_and_gtk_endpoints_enforce_domain_isolation_pii_minimization_and_task_update_rules(): void
    {
        $operator = User::factory()->create(['role' => 'operator']);
        $guru = User::factory()->create(['role' => 'guru']);

        $studentToken = app(IntegrationTokenService::class)->issue($operator, 'emis_siswa', 'Token Siswa');
        $gtkToken = app(IntegrationTokenService::class)->issue($operator, 'emis_gtk', 'Token GTK');

        // 1. Token emis_siswa tidak bisa mengakses endpoint /api/emis-gtk/* dan sebaliknya
        $this->getJson('/api/emis-gtk/reconciliation-batches', [
            'X-Integration-Token' => $studentToken['plain_token'],
        ])
            ->assertStatus(401);

        $this->getJson('/api/emis/reconciliation-batches', [
            'X-Integration-Token' => $gtkToken['plain_token'],
        ])
            ->assertStatus(401);

        // 2. Endpoint /api/emis/* menolak filter entity_type=staff (403 FORBIDDEN_ENTITY_SCOPE)
        $this->actingAs($operator)
            ->getJson('/api/emis/reconciliation-batches?entity_type=staff')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN_ENTITY_SCOPE');

        $this->actingAs($operator)
            ->getJson('/api/emis-gtk/update-tasks?entity_type=student')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN_ENTITY_SCOPE');

        // 3. Rekonsiliasi menghasilkan snapshot yang dimasking pada respons API (P1.3 PII minimization)
        $student = Student::factory()->create([
            'nisn' => '4000000001',
            'nik' => '3201999988887777',
            'name' => 'Siswa Uji Masking',
        ]);

        EmisStaging::create([
            'source' => 'bulk',
            'nisn' => '4000000001',
            'nik' => '3201999988887777',
            'name' => 'Siswa Uji Masking EMIS',
            'status' => 'pending',
            'raw_data' => [],
        ]);

        $this->actingAs($operator)->postJson('/api/emis/reconcile')->assertOk();

        $batchRes = $this->actingAs($operator)
            ->getJson('/api/emis/reconciliation-batches')
            ->assertOk()
            ->assertJsonStructure(['meta' => ['current_page', 'per_page', 'total', 'last_page']]);
        $this->assertNull($batchRes->json('data.0.items'));
        $this->assertSame(1, $batchRes->json('data.0.items_count'));

        $batchId = $batchRes->json('data.0.id');
        $itemsRes = $this->actingAs($operator)
            ->getJson("/api/emis/reconciliation-batches/{$batchId}/items")
            ->assertOk();

        $firstItem = $itemsRes->json('data.0');
        $this->assertNotNull($firstItem);
        $this->assertNotSame('3201999988887777', $firstItem['pintu_snapshot']['nik'] ?? null);
        $this->assertStringEndsWith('7777', (string) ($firstItem['pintu_snapshot']['nik'] ?? ''));

        // 4. Task update rules (P2.1):
        $studentTask = EmisUpdateTask::create([
            'entity_type' => 'student',
            'entity_id' => $student->id,
            'source_type' => 'student_manual_edit',
            'changed_fields' => ['nik' => ['old_value' => '3201000000000000', 'new_value' => '3201999988887777']],
            'status' => EmisUpdateTask::STATUS_PENDING,
        ]);

        // Integration token ditolak untuk mengubah status task
        $this->patchJson("/api/emis/update-tasks/{$studentTask->id}", [
            'status' => EmisUpdateTask::STATUS_COMPLETED,
        ], [
            'X-Integration-Token' => $studentToken['plain_token'],
        ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'INTEGRATION_TOKEN_TASK_UPDATE_FORBIDDEN');

        // User tanpa sync_emis (guru) ditolak
        $this->actingAs($guru)
            ->patchJson("/api/emis/update-tasks/{$studentTask->id}", [
                'status' => EmisUpdateTask::STATUS_COMPLETED,
            ])
            ->assertStatus(403);

        // Endpoint lintas domain (/api/emis-gtk/* untuk task student) ditolak
        $this->actingAs($operator)
            ->patchJson("/api/emis-gtk/update-tasks/{$studentTask->id}", [
                'status' => EmisUpdateTask::STATUS_COMPLETED,
            ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN_ENTITY_SCOPE');

        // Operator menyelesaikan task
        $this->actingAs($operator)
            ->patchJson("/api/emis/update-tasks/{$studentTask->id}", [
                'status' => EmisUpdateTask::STATUS_COMPLETED,
                'operator_notes' => 'Selesai diupdate di EMIS Siswa.',
            ])
            ->assertOk();

        // Membuka kembali task yang sudah completed tanpa reopen_confirmed & alasan wajib ditolak 422
        $this->actingAs($operator)
            ->patchJson("/api/emis/update-tasks/{$studentTask->id}", [
                'status' => EmisUpdateTask::STATUS_IN_PROGRESS,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_TASK_STATUS_TRANSITION');

        // Dengan reopen_confirmed=true dan alasan >= 10 karakter -> berhasil dibuka kembali
        $this->actingAs($operator)
            ->patchJson("/api/emis/update-tasks/{$studentTask->id}", [
                'status' => EmisUpdateTask::STATUS_IN_PROGRESS,
                'reopen_confirmed' => true,
                'operator_notes' => 'Koreksi ulang karena verifikasi kanwil.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', EmisUpdateTask::STATUS_IN_PROGRESS);
    }

    public function test_emis_api_masks_pii_in_snapshots_differences_changed_fields_and_staging_lists(): void
    {
        $operator = User::factory()->create(['role' => 'operator']);
        $studentToken = app(IntegrationTokenService::class)->issue($operator, 'emis_siswa', 'Token Siswa PII');

        $student = Student::factory()->create([
            'nisn' => '4000000002',
            'nik' => '3201888877776666',
            'kk_number' => '3201555544443333',
            'name' => 'Rahasia Nama Siswa',
            'birth_date' => '2010-03-04',
            'father_name' => 'Ayah Rahasia',
            'mother_name' => 'Ibu Rahasia',
            'guardian_nik' => '3201111122223333',
        ]);

        EmisStaging::create([
            'source' => 'bulk',
            'nisn' => '4000000002',
            'nik' => '3201888877776666',
            'name' => 'Rahasia Nama Siswa Berbeda',
            'status' => 'pending',
            'raw_data' => [],
        ]);

        $this->actingAs($operator)->postJson('/api/emis/reconcile')->assertOk();

        $batchId = $this->actingAs($operator)->getJson('/api/emis/reconciliation-batches')->json('data.0.id');

        $task = EmisUpdateTask::create([
            'entity_type' => 'student',
            'entity_id' => $student->id,
            'source_type' => 'student_manual_edit',
            'changed_fields' => [
                'nik' => ['old_value' => '3201000000000000', 'new_value' => '3201888877776666'],
                'name' => ['old_value' => 'Rahasia Lama', 'new_value' => 'Rahasia Nama Siswa'],
                'birth_date' => ['old_value' => '2009-01-01', 'new_value' => '2010-03-04'],
                'father_name' => ['old_value' => 'Ayah Lama', 'new_value' => 'Ayah Rahasia'],
            ],
            'status' => EmisUpdateTask::STATUS_PENDING,
        ]);

        $leaks = ['3201888877776666', '3201555544443333', '3201111122223333', '4000000002', 'Rahasia Nama Siswa', '2010-03-04', 'Ayah Rahasia', 'Ibu Rahasia', '3201000000000000', 'Ayah Lama', '2009-01-01'];
        $headers = ['X-Integration-Token' => $studentToken['plain_token']];

        foreach (["/api/emis/reconciliation-batches/{$batchId}/items", '/api/emis/update-tasks', '/api/emis/staging'] as $url) {
            $body = $this->getJson($url, $headers)->assertOk()->getContent();
            foreach ($leaks as $leak) {
                $this->assertStringNotContainsString($leak, $body, "PII bocor di {$url}: {$leak}");
            }
        }

        // Operator sesi tetap melihat NISN & nama utuh di staging untuk review UI
        $stagingOp = $this->actingAs($operator)->getJson('/api/emis/staging')->assertOk();
        $this->assertSame('4000000002', $stagingOp->json('data.0.nisn'));
        $this->assertSame('Rahasia Nama Siswa Berbeda', $stagingOp->json('data.0.name'));
        auth()->forgetGuards();

        // Respons token tetap usable: struktur & klasifikasi tersedia
        $items = $this->getJson("/api/emis/reconciliation-batches/{$batchId}/items", $headers)->assertOk();
        $this->assertNotEmpty($items->json('data.0.classification'));
        $this->assertNotNull($items->json('data.0.id'));

        // Operator sesi: identifier tetap dimasking, nama tetap terlihat untuk rekonsiliasi
        $opBody = $this->actingAs($operator)->getJson("/api/emis/reconciliation-batches/{$batchId}/items")->assertOk()->getContent();
        foreach (['3201888877776666', '3201555544443333', '3201111122223333', 'Ayah Rahasia'] as $leak) {
            $this->assertStringNotContainsString($leak, $opBody);
        }
        $this->assertStringContainsString('Rahasia Nama Siswa', $opBody);

        // Nested array
        $sanitized = app(EmisPiiSanitizer::class)->sanitize([
            'a' => ['b' => ['nik' => '3201888877776666', 'father_nik' => '3201000011112222', 'ok' => 'visible']],
        ], 'integration');
        $this->assertSame('visible', $sanitized['a']['b']['ok']);
        $this->assertStringNotContainsString('3201888877776666', json_encode($sanitized));
        $this->assertStringNotContainsString('3201000011112222', json_encode($sanitized));

        $this->assertNotNull($task->id);
    }

    public function test_every_student_and_staff_column_is_classified_and_personal_columns_are_sensitive(): void
    {
        $technical = [
            'id', 'created_at', 'updated_at', 'deleted_at', 'is_verified_by_ai', 'is_locked', 'profile_photo',
            'gdrive_photo_id', 'gdrive_photo_url', 'photo_upload_status', 'photo_upload_retries',
        ];
        $ownership = app(DataOwnershipService::class);
        $sanitizer = app(EmisPiiSanitizer::class);

        foreach (['student' => 'students', 'staff' => 'staff'] as $entity => $table) {
            foreach (Schema::getColumnListing($table) as $column) {
                if (in_array($column, $technical, true)) {
                    continue;
                }
                $this->assertNotNull($ownership->getFieldConfig($entity, $column), "Kolom {$table}.{$column} belum ada di data ownership dictionary");

                if (preg_match('/(nik|nisn|nip|nuptk|npk|phone|email|address|birth|father_|mother_|guardian_)/', $column)) {
                    $this->assertTrue($sanitizer->isSensitiveKey($column), "Kolom pribadi {$table}.{$column} belum disamarkan");
                }
            }
        }
    }
}
