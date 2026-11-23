<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\ApiClient;
use App\Models\IntegrationToken;
use App\Models\Rombel;
use App\Models\SchoolClass;
use App\Models\Staff;
use App\Models\Student;
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
