<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\ApiClient;
use App\Models\IntegrationToken;
use App\Models\OrganizationUnit;
use App\Models\Position;
use App\Models\PositionAssignment;
use App\Models\Rombel;
use App\Models\SchoolClass;
use App\Models\Staff;
use App\Models\Student;
use App\Models\StudentAcademicCohort;
use App\Models\StudentDocument;
use App\Models\User;
use App\Services\ApiKeyService;
use App\Services\IntegrationTokenService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ApiStandardAndSecurityTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_api_key_is_stored_as_sha256_hash_without_plaintext_and_supports_rotation(): void
    {
        $service = app(ApiKeyService::class);

        $created = $service->createClient([
            'name' => 'SIPSMAD Production',
            'scopes' => ['students:read', 'staff:read'],
            'notes' => 'Server SIPSMAD',
        ]);

        $client = $created['client'];
        $plainKey = $created['plain_key'];

        // 1. Plaintext tidak boleh tersimpan di DB
        $this->assertNull($client->getRawOriginal('api_key'));
        $this->assertSame(hash('sha256', $plainKey), $client->key_hash);
        $this->assertNotEmpty($client->key_prefix);
        $this->assertSame(substr($plainKey, -4), $client->key_last4);

        // 2. Key baru berhasil mengautentikasi endpoint sesuai scope
        $this->withHeaders([
            'X-API-KEY' => $plainKey,
            'X-Request-Id' => 'req-test-001',
        ])
            ->getJson('/api/v1/students')
            ->assertOk()
            ->assertHeader('X-Request-Id', 'req-test-001')
            ->assertJsonPath('meta.request_id', 'req-test-001');

        // 3. Rotasi key: key lama langsung mati, key baru aktif
        $rotated = $service->rotate($client);
        $newPlainKey = $rotated['plain_key'];

        $this->assertNotSame($plainKey, $newPlainKey);

        $this->withHeader('X-API-KEY', $plainKey)
            ->getJson('/api/v1/students')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'INVALID_API_KEY');

        $this->withHeader('X-API-KEY', $newPlainKey)
            ->getJson('/api/v1/students')
            ->assertOk();
    }

    public function test_revoked_expired_and_out_of_scope_keys_return_standardized_errors(): void
    {
        $service = app(ApiKeyService::class);

        $created = $service->createClient([
            'name' => 'PTSP Client',
            'scopes' => ['students:read'],
        ]);

        $client = $created['client'];
        $plainKey = $created['plain_key'];

        // 1. Scope deny (punya students:read tapi minta classes:read)
        $this->withHeader('X-API-KEY', $plainKey)
            ->getJson('/api/v1/classes')
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'FORBIDDEN_SCOPE')
            ->assertJsonStructure(['success', 'message', 'error' => ['code'], 'request_id']);

        // 2. Revoked key -> 403 API_KEY_REVOKED
        $service->setActive($client, false);

        $this->withHeader('X-API-KEY', $plainKey)
            ->getJson('/api/v1/students')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'API_KEY_REVOKED');

        // 3. Expired key -> 403 API_KEY_EXPIRED
        $service->setActive($client, true);
        $client->update(['expires_at' => now()->subDay()]);

        $this->withHeader('X-API-KEY', $plainKey)
            ->getJson('/api/v1/students')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'API_KEY_EXPIRED');
    }

    public function test_hash_existing_api_keys_command_backfills_and_clears_plaintext(): void
    {
        $legacyPlain = 'pm_live_legacy12_secretkeyforbackfilltesting1234567890';
        $id = '99999999-8888-7777-6666-555555555555';

        DB::table('api_clients')->insert([
            'id' => $id,
            'name' => 'Legacy Plaintext Client',
            'api_key' => $legacyPlain,
            'key_hash' => null,
            'key_prefix' => null,
            'key_last4' => null,
            'scopes' => json_encode(['students:read']),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('api-keys:hash-existing')
            ->assertExitCode(0);

        $client = ApiClient::findOrFail($id);
        $this->assertNull($client->getRawOriginal('api_key'));
        $this->assertSame(hash('sha256', $legacyPlain), $client->key_hash);

        // Key tetap berfungsi setelah plaintext dikosongkan
        $this->withHeader('X-API-KEY', $legacyPlain)
            ->getJson('/api/v1/students')
            ->assertOk();
    }

    public function test_api_v1_contracts_preserve_legacy_shape_and_support_by_id_fields_and_updated_since(): void
    {
        $service = app(ApiKeyService::class);
        $created = $service->createClient([
            'name' => 'Full V1 Consumer',
            'scopes' => ['students:read', 'staff:read', 'classes:read', 'academic-years:read'],
        ]);
        $headers = ['X-API-KEY' => $created['plain_key']];

        $year = AcademicYear::factory()->create(['is_active' => true]);
        $schoolClass = SchoolClass::factory()->create(['grade_level' => '7']);
        Rombel::factory()->create([
            'academic_year_id' => $year->id,
            'class_id' => $schoolClass->id,
        ]);

        // Siswa tanpa NISN (kasus siswa baru / PPDB)
        $studentWithoutNisn = Student::factory()->create([
            'nisn' => null,
            'name' => 'Siswa Baru Tanpa NISN',
            'status' => 'active',
        ]);
        Staff::factory()->create([
            'full_name_with_titles' => 'Guru Uji API, S.Pd.',
            'is_active' => true,
        ]);

        // 1. GET /api/v1/students?fields=id,name,status&per_page=250 (per_page dibatasi maks 100)
        $listRes = $this->withHeaders($headers)
            ->getJson('/api/v1/students?fields=id,name,status&per_page=250')
            ->assertOk()
            ->assertJsonStructure([
                'current_page',
                'data',
                'per_page',
                'total',
                'meta' => [
                    'request_id',
                    'generated_at',
                    'pagination' => ['page', 'per_page', 'total', 'last_page'],
                ],
            ]);

        $this->assertSame(100, $listRes->json('meta.pagination.per_page'));
        $firstRow = $listRes->json('data.0');
        $this->assertArrayHasKey('id', $firstRow);
        $this->assertArrayHasKey('name', $firstRow);
        $this->assertArrayHasKey('status', $firstRow);
        $this->assertArrayNotHasKey('father_name', $firstRow);

        // 2. GET /api/v1/students/by-id/{uuid} berhasil menemukan siswa tanpa NISN
        $this->withHeaders($headers)
            ->getJson("/api/v1/students/by-id/{$studentWithoutNisn->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $studentWithoutNisn->id)
            ->assertJsonPath('data.name', 'Siswa Baru Tanpa NISN')
            ->assertJsonStructure(['data', 'meta' => ['request_id', 'generated_at']]);

        // 3. GET /api/v1/staff, /api/v1/classes, /api/v1/academic-years/active
        $this->withHeaders($headers)
            ->getJson('/api/v1/staff?fields=id,full_name_with_titles,is_active')
            ->assertOk()
            ->assertJsonStructure(['data', 'current_page', 'meta' => ['request_id', 'pagination']]);

        $this->withHeaders($headers)
            ->getJson('/api/v1/classes')
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['request_id', 'generated_at']]);

        $this->withHeaders($headers)
            ->getJson('/api/v1/academic-years/active')
            ->assertOk()
            ->assertJsonPath('data.id', $year->id)
            ->assertJsonStructure(['data' => ['id', 'full_label'], 'meta' => ['request_id']]);
    }

    public function test_staff_and_student_api_default_to_non_pii_and_filter_requested_fields_by_client_scope(): void
    {
        $staff = Staff::factory()->create([
            'nik' => '1234567890123456',
            'phone' => '081234567890',
            'email' => 'private.staff@example.test',
        ]);
        $student = Student::factory()->create([
            'nisn' => '0011223344',
            'nik' => '9876543210123456',
            'kk_number' => '1111222233334444',
            'address' => 'Alamat privat',
            'father_name' => 'Nama Ayah Privat',
        ]);

        $readOnlyKey = app(ApiKeyService::class)->createClient([
            'name' => 'Non-PII consumer',
            'scopes' => ['students:read', 'staff:read'],
        ])['plain_key'];
        $identifierKey = app(ApiKeyService::class)->createClient([
            'name' => 'Student Identifier consumer',
            'scopes' => ['students:read', 'students:identifiers'],
        ])['plain_key'];
        $piiKey = app(ApiKeyService::class)->createClient([
            'name' => 'PII consumer',
            'scopes' => ['students:read', 'students:pii', 'staff:read', 'staff:pii'],
        ])['plain_key'];

        $this->withHeader('X-API-KEY', $readOnlyKey)
            ->getJson('/api/v1/staff')
            ->assertOk()
            ->assertJsonMissing(['nik' => $staff->nik])
            ->assertJsonMissing(['phone' => $staff->phone])
            ->assertJsonMissing(['email' => $staff->email]);

        $this->withHeader('X-API-KEY', $readOnlyKey)
            ->getJson('/api/v1/staff?fields=id,nik,phone,email')
            ->assertOk()
            ->assertJsonMissing(['nik' => $staff->nik])
            ->assertJsonMissing(['phone' => $staff->phone])
            ->assertJsonMissing(['email' => $staff->email]);

        // students:read saja tidak boleh mengekspos NISN ataupun lookup by NISN
        $this->withHeader('X-API-KEY', $readOnlyKey)
            ->getJson('/api/v1/students')
            ->assertOk()
            ->assertJsonMissing(['nisn' => $student->nisn]);

        $this->withHeader('X-API-KEY', $readOnlyKey)
            ->getJson('/api/v1/students?fields=id,nisn,nik,kk_number,address,father_name')
            ->assertOk()
            ->assertJsonMissing(['nisn' => $student->nisn])
            ->assertJsonMissing(['nik' => $student->nik])
            ->assertJsonMissing(['kk_number' => $student->kk_number])
            ->assertJsonMissing(['address' => 'Alamat privat'])
            ->assertJsonMissing(['father_name' => 'Nama Ayah Privat']);

        $this->withHeader('X-API-KEY', $readOnlyKey)
            ->getJson("/api/v1/students/{$student->nisn}")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN_SCOPE');

        // students:identifiers mengekspos NISN dan mengizinkan lookup by NISN, tetapi tetap menahan NIK/KK/alamat
        $this->withHeader('X-API-KEY', $identifierKey)
            ->getJson("/api/v1/students/{$student->nisn}?fields=id,nisn,nik,address")
            ->assertOk()
            ->assertJsonPath('data.nisn', $student->nisn)
            ->assertJsonMissing(['nik' => $student->nik])
            ->assertJsonMissing(['address' => 'Alamat privat']);

        $this->withHeader('X-API-KEY', $piiKey)
            ->getJson('/api/v1/staff?fields=id,nik,phone,email')
            ->assertOk()
            ->assertJsonFragment(['nik' => $staff->nik])
            ->assertJsonFragment(['phone' => $staff->phone])
            ->assertJsonFragment(['email' => $staff->email]);

        $this->withHeader('X-API-KEY', $piiKey)
            ->getJson('/api/v1/students?fields=id,nik,kk_number,address,father_name')
            ->assertOk()
            ->assertJsonFragment(['nik' => $student->nik])
            ->assertJsonFragment(['kk_number' => $student->kk_number])
            ->assertJsonFragment(['address' => 'Alamat privat'])
            ->assertJsonFragment(['father_name' => 'Nama Ayah Privat']);
    }

    public function test_nested_api_relations_use_dedicated_resources_and_prevent_pii_or_internal_attribute_leakage(): void
    {
        $year = AcademicYear::factory()->create(['is_active' => true]);
        $schoolClass = SchoolClass::factory()->create(['grade_level' => '8', 'major_name' => 'Reguler']);

        $homeroomTeacher = Staff::factory()->create([
            'full_name_with_titles' => 'Wali Kelas Delapan, S.Pd.',
            'nik' => '3201999988887777',
            'nip' => '198801012015011009',
            'nuptk' => '5544332211009988',
            'phone' => '081299998888',
            'email' => 'wali.rahasia@example.test',
            'tpg_status' => true,
        ]);

        $rombel = Rombel::factory()->create([
            'academic_year_id' => $year->id,
            'class_id' => $schoolClass->id,
            'name' => 'VIII-A',
            'homeroom_teacher_id' => $homeroomTeacher->id,
        ]);

        $student = Student::factory()->create([
            'nisn' => '0099887766',
            'name' => 'Siswa Uji Relasi',
            'status' => 'active',
        ]);

        StudentAcademicCohort::create([
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'semester' => $year->semester ?? 'Ganjil',
            'rombel_id' => $rombel->id,
            'attendance_number' => 7,
            'status' => 'active',
        ]);

        StudentDocument::create([
            'student_id' => $student->id,
            'document_type' => 'kartu_keluarga',
            'gdrive_file_id' => 'gdrive-secret-internal-id',
            'gdrive_view_url' => 'https://drive.google.com/file/d/preview-safe',
            'file_size' => 12345,
            'is_verified_ai' => true,
            'ai_verification_result' => [
                'extracted_nik' => '3201000011112222',
                'raw_ocr_text' => 'TEKS OCR KARTU KELUARGA RAHASIA',
            ],
            'status' => 'verified',
            'upload_retries' => 2,
        ]);

        $unit = OrganizationUnit::create([
            'code' => 'TU',
            'name' => 'Tata Usaha',
            'type' => 'supporting',
            'is_active' => true,
        ]);
        $position = Position::create([
            'org_unit_id' => $unit->id,
            'code' => 'KAUR-TU',
            'name' => 'Kepala Urusan TU',
            'level' => 2,
            'is_structural' => true,
        ]);
        PositionAssignment::create([
            'staff_id' => $homeroomTeacher->id,
            'position_id' => $position->id,
            'org_unit_id' => $unit->id,
            'sk_number' => 'SK/001/2026',
            'valid_from' => '2026-01-01',
            'is_primary' => true,
            'status' => 'active',
        ]);

        $nonPiiClient = app(ApiKeyService::class)->createClient([
            'name' => 'Nested Non-PII Client',
            'scopes' => ['students:read', 'staff:read', 'classes:read'],
        ]);
        $piiStudentOnlyClient = app(ApiKeyService::class)->createClient([
            'name' => 'Student PII Without Staff PII',
            'scopes' => ['students:read', 'students:pii', 'classes:read'],
        ]);

        // 1. /api/v1/classes dengan scope classes:read TIDAK boleh membocorkan PII wali kelas (homeroom_teacher)
        $classesResponse = $this->withHeader('X-API-KEY', $nonPiiClient['plain_key'])
            ->getJson('/api/v1/classes')
            ->assertOk()
            ->assertJsonPath('data.0.homeroom_teacher.id', $homeroomTeacher->id)
            ->assertJsonPath('data.0.homeroom_teacher.full_name_with_titles', 'Wali Kelas Delapan, S.Pd.');

        $teacherPayload = $classesResponse->json('data.0.homeroom_teacher');
        $this->assertArrayNotHasKey('nik', $teacherPayload);
        $this->assertArrayNotHasKey('nip', $teacherPayload);
        $this->assertArrayNotHasKey('nuptk', $teacherPayload);
        $this->assertArrayNotHasKey('phone', $teacherPayload);
        $this->assertArrayNotHasKey('email', $teacherPayload);
        $this->assertArrayNotHasKey('tpg_status', $teacherPayload);

        // 2. /api/v1/students dengan include=currentCohort.rombel.homeroomTeacher tetap memfilter PII wali kelas
        $studentNestedRes = $this->withHeader('X-API-KEY', $nonPiiClient['plain_key'])
            ->getJson('/api/v1/students?include=currentCohort.rombel.homeroomTeacher')
            ->assertOk()
            ->assertJsonPath('data.0.current_cohort.rombel.name', 'VIII-A')
            ->assertJsonPath('data.0.current_cohort.rombel.homeroom_teacher.full_name_with_titles', 'Wali Kelas Delapan, S.Pd.');

        $nestedTeacher = $studentNestedRes->json('data.0.current_cohort.rombel.homeroom_teacher');
        $this->assertArrayNotHasKey('nik', $nestedTeacher);
        $this->assertArrayNotHasKey('nip', $nestedTeacher);
        $this->assertArrayNotHasKey('phone', $nestedTeacher);
        $this->assertArrayNotHasKey('email', $nestedTeacher);

        // 3. Klien dengan students:pii dapat meminta documents, tetapi hasil OCR mentah AI (ai_verification_result)
        // dan gdrive_file_id internal tidak bocor.
        $studentDetailRes = $this->withHeader('X-API-KEY', $piiStudentOnlyClient['plain_key'])
            ->getJson("/api/v1/students/{$student->nisn}?fields=id,nisn,name,documents,cohorts")
            ->assertOk()
            ->assertJsonPath('data.documents.0.document_type', 'kartu_keluarga')
            ->assertJsonPath('data.documents.0.document_type_label', 'Kartu Keluarga')
            ->assertJsonPath('data.documents.0.status', 'verified');

        $docPayload = $studentDetailRes->json('data.documents.0');
        $this->assertArrayNotHasKey('ai_verification_result', $docPayload);
        $this->assertArrayNotHasKey('gdrive_file_id', $docPayload);
        $this->assertArrayNotHasKey('upload_retries', $docPayload);

        // 4. /api/v1/staff?include=positionAssignments mengembalikan struktur jabatan dari PositionAssignmentResource
        $staffRes = $this->withHeader('X-API-KEY', $nonPiiClient['plain_key'])
            ->getJson('/api/v1/staff?include=positionAssignments')
            ->assertOk()
            ->assertJsonPath('data.0.position_assignments.0.sk_number', 'SK/001/2026')
            ->assertJsonPath('data.0.position_assignments.0.position.code', 'KAUR-TU')
            ->assertJsonPath('data.0.position_assignments.0.unit.code', 'TU');

        $this->assertArrayNotHasKey('tpg_status', $staffRes->json('data.0'));
    }

    public function test_emis_endpoints_require_valid_integration_token_or_operator_session_and_validate_jwt_exp(): void
    {
        $operator = User::factory()->create(['role' => 'operator']);
        $tokenService = app(IntegrationTokenService::class);

        // 1. Tanpa token dan tanpa sesi login -> 401 UNAUTHORIZED_INTEGRATION_TOKEN
        $this->postJson('/api/emis/token', [
            'token' => 'eyJhbGciOiJIUzI1NiJ9.eyJleHAiOjk5OTk5OTk5OTl9.signature',
        ])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHORIZED_INTEGRATION_TOKEN');

        $this->getJson('/api/emis/status')
            ->assertStatus(401);

        // 2. Terbitkan token integrasi untuk emis_siswa
        $issued = $tokenService->issue(
            user: $operator,
            purpose: IntegrationToken::PURPOSE_EMIS_SISWA,
            label: 'Bookmarklet Operator TU',
        );
        $plainIntegrationToken = $issued['plain_token'];

        // 3. Token emis_siswa dipakai ke endpoint emis-gtk -> ditolak 401 (purpose mismatch)
        $this->withHeader('X-Integration-Token', $plainIntegrationToken)
            ->getJson('/api/emis-gtk/staging')
            ->assertStatus(401);

        // 4. Kirim JWT EMIS yang sudah expired (exp di masa lalu) -> ditolak 422 EXPIRED_JWT_TOKEN
        $expiredPayload = rtrim(strtr(base64_encode(json_encode(['sub' => '123', 'exp' => time() - 3600])), '+/', '-_'), '=');
        $expiredJwt = "eyJhbGciOiJIUzI1NiJ9.{$expiredPayload}.sig1234567890";

        $this->withHeader('X-Integration-Token', $plainIntegrationToken)
            ->postJson('/api/emis/token', ['token' => $expiredJwt])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'EXPIRED_JWT_TOKEN');

        // 5. Kirim JWT EMIS yang masih berlaku -> 200 OK & tercatat di integration_logs tanpa bocor token
        $validPayload = rtrim(strtr(base64_encode(json_encode(['sub' => '123', 'exp' => time() + 7200])), '+/', '-_'), '=');
        $validJwt = "eyJhbGciOiJIUzI1NiJ9.{$validPayload}.sig1234567890";

        $this->withHeader('X-Integration-Token', $plainIntegrationToken)
            ->postJson('/api/emis/token', ['token' => $validJwt])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('integration_logs', [
            'channel' => 'emis_siswa',
            'event' => 'emis_token.received',
            'status' => 'success',
        ]);

        // 6. Setelah token integrasi dicabut (revoke) -> 401
        $tokenService->revoke($issued['token'], $operator);

        $this->withHeader('X-Integration-Token', $plainIntegrationToken)
            ->getJson('/api/emis/status')
            ->assertStatus(401);

        // 7. Fallback sesi web operator yang sudah login tetap berfungsi untuk UI internal (tanpa header X-Integration-Token)
        $this->withoutHeader('X-Integration-Token')
            ->actingAs($operator, 'web')
            ->getJson('/api/emis/status')
            ->assertOk()
            ->assertJsonStructure(['emis_siswa', 'emis_gtk']);
    }
}
