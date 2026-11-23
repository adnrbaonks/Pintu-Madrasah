<?php

namespace Tests\Feature;

use App\Models\OutboxEvent;
use App\Models\Staff;
use App\Models\StaffChangeRequest;
use App\Models\StaffDocument;
use App\Models\User;
use App\Services\StaffChangeRequestService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StaffChangeRequestWorkflowTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_end_to_end_local_field_change_request_from_draft_to_completed(): void
    {
        Storage::fake('local');

        $staff = Staff::factory()->create([
            'full_name_with_titles' => 'Ahmad Zainuddin, S.Pd.',
            'last_title' => 'S.Pd.',
            'phone' => '08111111111',
            'highest_education' => 'S1',
        ]);
        $guru = User::factory()->create([
            'role' => 'guru',
            'staff_id' => $staff->id,
        ]);
        $kaurTu = User::factory()->create(['role' => 'kaur_tu']);
        $kamad = User::factory()->create(['role' => 'kepala_madrasah']);

        // 1. Guru membuat draf pengajuan dengan lampiran PDF
        $attachment = UploadedFile::fake()->create('ijazah-s2.pdf', 150, 'application/pdf');

        $this->actingAs($guru)
            ->post('/profil-gtk/pengajuan', [
                'reason' => 'Kelulusan S2 Pendidikan Agama Islam & ganti nomor WA',
                'changes' => [
                    'full_name_with_titles' => 'Ahmad Zainuddin, S.Pd., M.Pd.',
                    'last_title' => 'S.Pd., M.Pd.',
                    'phone' => '08222222222',
                    'highest_education' => 'S2',
                ],
                'attachments' => [$attachment],
                'submit_now' => false,
            ])
            ->assertRedirect();

        $changeRequest = StaffChangeRequest::where('staff_id', $staff->id)->firstOrFail();
        $this->assertSame(StaffChangeRequest::STATUS_DRAFT, $changeRequest->status);
        $this->assertSame(4, $changeRequest->items()->count());
        $this->assertSame(1, $changeRequest->attachments()->count());

        // Master belum berubah saat masih draf
        $this->assertSame('08111111111', $staff->fresh()->phone);

        // 2. Guru mengirim pengajuan (draft -> under_tu_review)
        $this->actingAs($guru)
            ->post("/profil-gtk/pengajuan/{$changeRequest->id}/submit")
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame(StaffChangeRequest::STATUS_UNDER_TU_REVIEW, $changeRequest->fresh()->status);

        // 3. Kaur TU memverifikasi pengajuan (under_tu_review -> pending_head_approval)
        $this->actingAs($kaurTu)
            ->post("/verifikasi-gtk/{$changeRequest->id}/verify", [
                'notes' => 'Ijazah S2 asli telah diperiksa dan sesuai.',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame(StaffChangeRequest::STATUS_PENDING_HEAD_APPROVAL, $changeRequest->fresh()->status);
        $this->assertDatabaseHas('staff_change_approvals', [
            'request_id' => $changeRequest->id,
            'stage' => 'tu_review',
            'actor_user_id' => $kaurTu->id,
            'decision' => StaffChangeRequest::STATUS_VERIFIED,
        ]);

        // 4. Kepala Madrasah menyetujui pengajuan (pending_head_approval -> approved -> applying -> completed)
        $this->actingAs($kamad)
            ->post("/persetujuan-gtk/{$changeRequest->id}/approve", [
                'notes' => 'Disetujui untuk diperbarui di data induk.',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $changeRequest->refresh();
        $this->assertSame(StaffChangeRequest::STATUS_COMPLETED, $changeRequest->status);
        $this->assertNotNull($changeRequest->decided_at);

        // Master staff wajib berubah
        $staff->refresh();
        $this->assertSame('Ahmad Zainuddin, S.Pd., M.Pd.', $staff->full_name_with_titles);
        $this->assertSame('S.Pd., M.Pd.', $staff->last_title);
        $this->assertSame('08222222222', $staff->phone);
        $this->assertSame('S2', $staff->highest_education);

        // History per item tercatat
        $this->assertSame(4, $changeRequest->histories()->count());

        // Transactional outbox tercatat tanpa NIK
        $outbox = OutboxEvent::where('event_type', 'staff.updated')
            ->where('aggregate_id', $staff->id)
            ->latest()
            ->first();
        $this->assertNotNull($outbox);
        $this->assertArrayNotHasKey('nik', $outbox->payload);

        // Pemanggilan ulang apply() bersifat idempoten
        $service = app(StaffChangeRequestService::class);
        $service->apply($changeRequest, $kamad);
        $this->assertSame(4, $changeRequest->histories()->count());
    }

    public function test_emis_owned_field_is_marked_pending_external_sync_and_not_overwritten_locally(): void
    {
        $staff = Staff::factory()->create([
            'phone' => '08123000000',
            'civil_rank' => 'III/a',
        ]);
        $guru = User::factory()->create([
            'role' => 'guru',
            'staff_id' => $staff->id,
        ]);
        $kaurTu = User::factory()->create(['role' => 'kaur_tu']);
        $kamad = User::factory()->create(['role' => 'kepala_madrasah']);

        $this->actingAs($guru)
            ->post('/profil-gtk/pengajuan', [
                'reason' => 'Kenaikan pangkat III/b dan update nomor telepon',
                'changes' => [
                    'phone' => '08123999999',
                    'civil_rank' => 'III/b',
                ],
                'submit_now' => true,
            ])
            ->assertRedirect();

        $changeRequest = StaffChangeRequest::where('staff_id', $staff->id)->firstOrFail();

        $this->actingAs($kaurTu)
            ->post("/verifikasi-gtk/{$changeRequest->id}/verify", [
                'notes' => 'SK Kenaikan Pangkat lengkap.',
            ])
            ->assertRedirect();

        $this->actingAs($kamad)
            ->post("/persetujuan-gtk/{$changeRequest->id}/approve", [
                'notes' => 'Disetujui.',
            ])
            ->assertRedirect();

        $changeRequest->refresh();
        $this->assertSame(StaffChangeRequest::STATUS_PENDING_EXTERNAL_SYNC, $changeRequest->status);

        // Field lokal (phone) berubah, tetapi field EMIS (civil_rank) tetap III/a sampai sinkronisasi EMIS
        $staff->refresh();
        $this->assertSame('08123999999', $staff->phone);
        $this->assertSame('III/a', $staff->civil_rank);

        $this->assertDatabaseHas('staff_change_request_items', [
            'request_id' => $changeRequest->id,
            'field' => 'phone',
            'apply_status' => 'applied',
        ]);
        $this->assertDatabaseHas('staff_change_request_items', [
            'request_id' => $changeRequest->id,
            'field' => 'civil_rank',
            'apply_status' => 'pending_external_sync',
        ]);
    }

    public function test_requester_cannot_verify_or_approve_their_own_request(): void
    {
        $staffTu = Staff::factory()->create(['phone' => '08110001']);
        $kaurTu = User::factory()->create([
            'role' => 'kaur_tu',
            'staff_id' => $staffTu->id,
        ]);
        $operator = User::factory()->create(['role' => 'operator']);

        // Kaur TU mengajukan perubahan datanya sendiri
        $this->actingAs($kaurTu)
            ->post('/profil-gtk/pengajuan', [
                'reason' => 'Update telepon Kaur TU',
                'changes' => ['phone' => '08110002'],
                'submit_now' => true,
            ])
            ->assertRedirect();

        $tuRequest = StaffChangeRequest::where('staff_id', $staffTu->id)->firstOrFail();

        // Kaur TU mencoba memverifikasi pengajuannya sendiri -> wajib ditolak!
        $this->actingAs($kaurTu)
            ->post("/verifikasi-gtk/{$tuRequest->id}/verify", ['notes' => 'Self verify'])
            ->assertSessionHasErrors('decision');

        $this->assertSame(StaffChangeRequest::STATUS_UNDER_TU_REVIEW, $tuRequest->fresh()->status);

        // Operator mencoba memverifikasi pengajuan -> ditolak 403 (bukan wewenang Kaur TU)
        $this->actingAs($operator)
            ->post("/verifikasi-gtk/{$tuRequest->id}/verify", ['notes' => 'Verified by operator'])
            ->assertForbidden();

        // Kaur TU lain memverifikasi pengajuan Kaur TU -> berhasil
        $anotherKaurTu = User::factory()->create(['role' => 'kaur_tu']);
        $this->actingAs($anotherKaurTu)
            ->post("/verifikasi-gtk/{$tuRequest->id}/verify", ['notes' => 'Verified by peer TU'])
            ->assertSessionHasNoErrors();

        $this->assertSame(StaffChangeRequest::STATUS_PENDING_HEAD_APPROVAL, $tuRequest->fresh()->status);

        // Kepala Madrasah mengajukan perubahan datanya sendiri
        $staffKamad = Staff::factory()->create(['phone' => '08550001']);
        $kamad = User::factory()->create([
            'role' => 'kepala_madrasah',
            'staff_id' => $staffKamad->id,
        ]);

        $this->actingAs($kamad)
            ->post('/profil-gtk/pengajuan', [
                'reason' => 'Update telepon Kamad',
                'changes' => ['phone' => '08550002'],
                'submit_now' => true,
            ])
            ->assertRedirect();

        $kamadRequest = StaffChangeRequest::where('staff_id', $staffKamad->id)->firstOrFail();

        $this->actingAs($kaurTu)
            ->post("/verifikasi-gtk/{$kamadRequest->id}/verify", ['notes' => 'Verified by TU'])
            ->assertSessionHasNoErrors();

        // Kamad mencoba menyetujui pengajuannya sendiri -> wajib ditolak!
        $this->actingAs($kamad)
            ->post("/persetujuan-gtk/{$kamadRequest->id}/approve", ['notes' => 'Self approve'])
            ->assertSessionHasErrors('decision');

        $this->assertSame(StaffChangeRequest::STATUS_PENDING_HEAD_APPROVAL, $kamadRequest->fresh()->status);
    }

    public function test_revision_requested_and_rejection_do_not_modify_master_data(): void
    {
        $staff = Staff::factory()->create(['phone' => '0811223344']);
        $guru = User::factory()->create(['role' => 'guru', 'staff_id' => $staff->id]);
        $kaurTu = User::factory()->create(['role' => 'kaur_tu']);

        $this->actingAs($guru)
            ->post('/profil-gtk/pengajuan', [
                'reason' => 'Ganti nomor WA',
                'changes' => ['phone' => '0899887766'],
                'submit_now' => true,
            ])
            ->assertRedirect();

        $changeRequest = StaffChangeRequest::where('staff_id', $staff->id)->firstOrFail();

        // 1. Kaur TU meminta revisi
        $this->actingAs($kaurTu)
            ->post("/verifikasi-gtk/{$changeRequest->id}/revision", [
                'notes' => 'Mohon lampirkan surat keterangan.',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(StaffChangeRequest::STATUS_REVISION_REQUESTED, $changeRequest->fresh()->status);
        $this->assertSame('0811223344', $staff->fresh()->phone);

        // 2. Guru memperbarui dan mengirim kembali
        $this->actingAs($guru)
            ->put("/profil-gtk/pengajuan/{$changeRequest->id}", [
                'reason' => 'Ganti nomor WA (sudah diperbaiki)',
                'changes' => ['phone' => '0899887755'],
                'submit_now' => true,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(StaffChangeRequest::STATUS_UNDER_TU_REVIEW, $changeRequest->fresh()->status);

        // 3. Kaur TU menolak pengajuan
        $this->actingAs($kaurTu)
            ->post("/verifikasi-gtk/{$changeRequest->id}/reject", [
                'notes' => 'Data nomor tidak valid.',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(StaffChangeRequest::STATUS_REJECTED, $changeRequest->fresh()->status);
        $this->assertSame('0811223344', $staff->fresh()->phone);
    }

    public function test_authorization_isolation_and_self_service_documents(): void
    {
        Storage::fake('local');

        $staffA = Staff::factory()->create(['phone' => '08111']);
        $guruA = User::factory()->create(['role' => 'guru', 'staff_id' => $staffA->id]);

        $staffB = Staff::factory()->create(['phone' => '08222']);
        $guruB = User::factory()->create(['role' => 'guru', 'staff_id' => $staffB->id]);

        $kaurTu = User::factory()->create(['role' => 'kaur_tu']);

        // Guru A mengakses halaman layanan mandiri
        $this->actingAs($guruA)
            ->get('/profil-gtk')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('StaffSelf/Profile', false)
                ->where('staff.id', $staffA->id)
            );

        // Guru A membuat pengajuan draf
        $this->actingAs($guruA)
            ->post('/profil-gtk/pengajuan', [
                'reason' => 'Pengajuan Guru A',
                'changes' => ['phone' => '08119'],
                'submit_now' => false,
            ])
            ->assertRedirect();

        $requestA = StaffChangeRequest::where('staff_id', $staffA->id)->firstOrFail();

        // Guru B tidak boleh melihat atau membatalkan pengajuan Guru A (IDOR protection)
        $this->actingAs($guruB)
            ->get("/profil-gtk/pengajuan/{$requestA->id}")
            ->assertForbidden();

        $this->actingAs($guruB)
            ->post("/profil-gtk/pengajuan/{$requestA->id}/cancel")
            ->assertForbidden();

        // Guru tidak boleh mengakses halaman verifikasi TU maupun persetujuan Kamad
        $this->actingAs($guruA)->get('/verifikasi-gtk')->assertForbidden();
        $this->actingAs($guruA)->get('/persetujuan-gtk')->assertForbidden();

        // Kaur TU boleh mengakses verifikasi TU tetapi tidak boleh mengakses persetujuan Kamad
        $this->actingAs($kaurTu)->get('/verifikasi-gtk')->assertOk();
        $this->actingAs($kaurTu)->get('/persetujuan-gtk')->assertForbidden();

        // Guru A mengunggah dokumen kepegawaian sendiri
        $this->actingAs($guruA)
            ->post('/profil-gtk/dokumen', [
                'document_type' => 'sertifikat_pendidik',
                'title' => 'Serdik Guru A',
                'file' => UploadedFile::fake()->create('serdik.pdf', 100, 'application/pdf'),
            ])
            ->assertRedirect();

        $docA = StaffDocument::where('staff_id', $staffA->id)->firstOrFail();

        // Guru B tidak boleh menghapus dokumen Guru A
        $this->actingAs($guruB)
            ->delete("/profil-gtk/dokumen/{$docA->id}")
            ->assertForbidden();

        // Guru A boleh menghapus dokumennya sendiri
        $this->actingAs($guruA)
            ->delete("/profil-gtk/dokumen/{$docA->id}")
            ->assertRedirect();

        $this->assertDatabaseMissing('staff_documents', ['id' => $docA->id]);
    }

    public function test_technical_admin_staff_and_operator_cannot_verify_or_approve_change_requests(): void
    {
        $staff = Staff::factory()->create(['phone' => '0812345678']);
        $guru = User::factory()->create(['role' => 'guru', 'staff_id' => $staff->id]);
        $admin = User::factory()->create(['role' => 'admin']);
        $staf = User::factory()->create(['role' => 'staf']);
        $operator = User::factory()->create(['role' => 'operator']);

        // Buat pengajuan berstatus under_tu_review
        $this->actingAs($guru)->post('/profil-gtk/pengajuan', [
            'reason' => 'Uji otorisasi penolakan role non-pejabat',
            'changes' => ['phone' => '0899999999'],
            'submit_now' => true,
        ])->assertRedirect();

        $changeRequest = StaffChangeRequest::where('staff_id', $staff->id)->firstOrFail();

        // 1. Verifikasi TU ditolak untuk Admin, Staf, dan Operator
        foreach ([$admin, $staf, $operator] as $unauthorizedUser) {
            $this->actingAs($unauthorizedUser)->get('/verifikasi-gtk')->assertForbidden();
            $this->actingAs($unauthorizedUser)
                ->post("/verifikasi-gtk/{$changeRequest->id}/verify", ['notes' => 'Attempt by unauthorized'])
                ->assertForbidden();
        }

        // 2. Sekarang kita majukan pengajuan ke pending_head_approval lewat Kaur TU yang sah
        $kaurTu = User::factory()->create(['role' => 'kaur_tu']);
        $this->actingAs($kaurTu)
            ->post("/verifikasi-gtk/{$changeRequest->id}/verify", ['notes' => 'Sah diverifikasi TU'])
            ->assertRedirect();

        $this->assertSame(StaffChangeRequest::STATUS_PENDING_HEAD_APPROVAL, $changeRequest->fresh()->status);

        // 3. Persetujuan Kepala Madrasah ditolak untuk Admin, Kaur TU, Staf, dan Operator
        foreach ([$admin, $kaurTu, $staf, $operator] as $unauthorizedApprover) {
            $this->actingAs($unauthorizedApprover)->get('/persetujuan-gtk')->assertForbidden();
            $this->actingAs($unauthorizedApprover)
                ->post("/persetujuan-gtk/{$changeRequest->id}/approve", ['notes' => 'Attempt approve'])
                ->assertForbidden();
        }
    }

    public function test_self_decision_blocked_when_approver_is_linked_to_same_staff_via_different_account(): void
    {
        $sharedStaff = Staff::factory()->create(['phone' => '0877777777']);

        // Akun pemohon (misal akun guru)
        $requesterUser = User::factory()->create([
            'role' => 'guru',
            'staff_id' => $sharedStaff->id,
        ]);

        // Akun verifikator Kaur TU tetapi terhubung ke data Staff yang sama!
        $kaurTuSameStaff = User::factory()->create([
            'role' => 'kaur_tu',
            'staff_id' => $sharedStaff->id,
        ]);

        // Akun Kepala Madrasah yang juga terhubung ke data Staff yang sama
        $kamadSameStaff = User::factory()->create([
            'role' => 'kepala_madrasah',
            'staff_id' => $sharedStaff->id,
        ]);

        $this->actingAs($requesterUser)->post('/profil-gtk/pengajuan', [
            'reason' => 'Uji pencegahan self-decision via staff_id',
            'changes' => ['phone' => '0877777778'],
            'submit_now' => true,
        ])->assertRedirect();

        $changeRequest = StaffChangeRequest::where('staff_id', $sharedStaff->id)->firstOrFail();

        // Kaur TU dengan staff_id sama harus ditolak meskipun beda user_id
        $this->actingAs($kaurTuSameStaff)
            ->post("/verifikasi-gtk/{$changeRequest->id}/verify", ['notes' => 'Beda user tapi staff sama'])
            ->assertSessionHasErrors('decision');

        // Lakukan verifikasi dengan Kaur TU lain
        $legitKaurTu = User::factory()->create(['role' => 'kaur_tu']);
        $this->actingAs($legitKaurTu)
            ->post("/verifikasi-gtk/{$changeRequest->id}/verify", ['notes' => 'TU sah'])
            ->assertRedirect();

        // Kamad dengan staff_id sama harus ditolak meskipun beda user_id
        $this->actingAs($kamadSameStaff)
            ->post("/persetujuan-gtk/{$changeRequest->id}/approve", ['notes' => 'Kamad akun terpisah tapi staff sama'])
            ->assertSessionHasErrors('decision');
    }

    public function test_explicit_null_and_empty_string_field_clearing_can_be_submitted_and_applied(): void
    {
        $staff = Staff::factory()->create([
            'phone' => '081234567890',
            'last_title' => 'S.Pd.',
            'birth_place' => 'Bandung',
        ]);
        $guru = User::factory()->create(['role' => 'guru', 'staff_id' => $staff->id]);
        $kaurTu = User::factory()->create(['role' => 'kaur_tu']);
        $kamad = User::factory()->create(['role' => 'kepala_madrasah']);

        // Mengajukan pengosongan phone (string kosong '') dan last_title (null)
        $this->actingAs($guru)->post('/profil-gtk/pengajuan', [
            'reason' => 'Hapus gelar dan kosongkan nomor telepon sementara',
            'changes' => [
                'phone' => '',
                'last_title' => null,
            ],
            'submit_now' => true,
        ])->assertRedirect();

        $changeRequest = StaffChangeRequest::where('staff_id', $staff->id)->firstOrFail();
        $this->assertSame(2, $changeRequest->items()->count());

        $phoneItem = $changeRequest->items()->where('field', 'phone')->first();
        $this->assertNull($phoneItem->proposed_value);
        $this->assertSame('081234567890', $phoneItem->old_value);

        $titleItem = $changeRequest->items()->where('field', 'last_title')->first();
        $this->assertNull($titleItem->proposed_value);
        $this->assertSame('S.Pd.', $titleItem->old_value);

        // Verifikasi TU & Persetujuan Kamad
        $this->actingAs($kaurTu)->post("/verifikasi-gtk/{$changeRequest->id}/verify")->assertRedirect();
        $this->actingAs($kamad)->post("/persetujuan-gtk/{$changeRequest->id}/approve")->assertRedirect();

        $staff->refresh();
        $this->assertNull($staff->phone);
        $this->assertNull($staff->last_title);
        $this->assertSame('Bandung', $staff->birth_place); // Tidak terpengaruh
    }

    public function test_reconciliation_of_pending_external_sync_when_emis_sync_completes(): void
    {
        $staff = Staff::factory()->create([
            'civil_rank' => 'III/a',
        ]);
        $guru = User::factory()->create(['role' => 'guru', 'staff_id' => $staff->id]);
        $kaurTu = User::factory()->create(['role' => 'kaur_tu']);
        $kamad = User::factory()->create(['role' => 'kepala_madrasah']);

        $this->actingAs($guru)->post('/profil-gtk/pengajuan', [
            'reason' => 'Update kepangkatan EMIS',
            'changes' => ['civil_rank' => 'III/b'],
            'submit_now' => true,
        ])->assertRedirect();

        $changeRequest = StaffChangeRequest::where('staff_id', $staff->id)->firstOrFail();
        $this->actingAs($kaurTu)->post("/verifikasi-gtk/{$changeRequest->id}/verify")->assertRedirect();
        $this->actingAs($kamad)->post("/persetujuan-gtk/{$changeRequest->id}/approve")->assertRedirect();

        $changeRequest->refresh();
        $this->assertSame(StaffChangeRequest::STATUS_PENDING_EXTERNAL_SYNC, $changeRequest->status);

        // Mensimulasikan hasil sinkronisasi EMIS (master data diperbarui ke nilai proposed)
        $staff->update(['civil_rank' => 'III/b']);

        // Panggil rekonsiliasi
        $service = app(StaffChangeRequestService::class);
        $service->reconcileExternalSync($changeRequest);

        $changeRequest->refresh();
        $this->assertSame(StaffChangeRequest::STATUS_COMPLETED, $changeRequest->status);

        $item = $changeRequest->items()->where('field', 'civil_rank')->first();
        $this->assertSame('applied', $item->apply_status);
    }
}
