<?php

namespace Tests\Feature;

use App\Models\ApiClient;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class IdempotencyKeyTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        (new RolesAndPermissionsSeeder)->run();

        // Siapkan test route untuk menguji middleware api.idempotent
        Route::post('/api/v1/test-idempotent-write', function (Request $request) {
            static $callCount = 0;
            $callCount++;

            return response()->json([
                'success' => true,
                'call_count' => $callCount,
                'data' => $request->all(),
            ], 201);
        })->middleware(['api.key', 'api.idempotent']);

        Route::put('/api/v1/test-idempotent-write', function (Request $request) {
            return response()->json([
                'success' => true,
                'method' => 'PUT',
                'data' => $request->all(),
            ], 200);
        })->middleware(['api.key', 'api.idempotent']);

        Route::get('/api/v1/test-idempotent-read', function () {
            return response()->json(['success' => true, 'type' => 'read']);
        })->middleware(['api.key', 'api.idempotent']);
    }

    public function test_first_request_executes_and_replay_returns_cached_response_with_header(): void
    {
        $client = ApiClient::create([
            'name' => 'Sistem Klien A',
            'api_key' => 'pm_live_11111111_client_a_secret_key_12345678901234',
            'scopes' => ['students:read'],
            'is_active' => true,
        ]);

        $key = 'idempotent-uuid-001';
        $payload = ['title' => 'Pendaftaran Baru', 'code' => 'REG-101'];

        // Request 1: eksekusi baru
        $response1 = $this->withHeaders([
            'X-API-KEY' => 'pm_live_11111111_client_a_secret_key_12345678901234',
            'Idempotency-Key' => $key,
        ])->postJson('/api/v1/test-idempotent-write', $payload);

        $response1->assertStatus(201)
            ->assertHeaderMissing('X-Idempotent-Replay')
            ->assertJson([
                'success' => true,
                'call_count' => 1,
            ]);

        // Request 2: replay dengan key & payload yang sama
        $response2 = $this->withHeaders([
            'X-API-KEY' => 'pm_live_11111111_client_a_secret_key_12345678901234',
            'Idempotency-Key' => $key,
        ])->postJson('/api/v1/test-idempotent-write', $payload);

        $response2->assertStatus(201)
            ->assertHeader('X-Idempotent-Replay', 'true')
            ->assertJson([
                'success' => true,
                'call_count' => 1, // Controller tidak dipanggil ulang
            ]);
    }

    public function test_rejects_same_key_with_different_payload(): void
    {
        ApiClient::create([
            'name' => 'Sistem Klien A',
            'api_key' => 'pm_live_11111111_client_a_secret_key_12345678901234',
            'scopes' => ['students:read'],
            'is_active' => true,
        ]);

        $key = 'idempotent-uuid-002';

        // Request 1: payload A
        $this->withHeaders([
            'X-API-KEY' => 'pm_live_11111111_client_a_secret_key_12345678901234',
            'Idempotency-Key' => $key,
        ])->postJson('/api/v1/test-idempotent-write', [
            'amount' => 50000,
        ])->assertStatus(201);

        // Request 2: key sama tetapi payload berbeda -> wajib ditolak HTTP 422
        $responseConflict = $this->withHeaders([
            'X-API-KEY' => 'pm_live_11111111_client_a_secret_key_12345678901234',
            'Idempotency-Key' => $key,
        ])->postJson('/api/v1/test-idempotent-write', [
            'amount' => 999999, // payload berubah
        ]);

        $responseConflict->assertStatus(422)
            ->assertHeaderMissing('X-Idempotent-Replay')
            ->assertJson([
                'success' => false,
                'error' => [
                    'code' => 'IDEMPOTENCY_PAYLOAD_MISMATCH',
                    'idempotency_key' => $key,
                ],
            ]);
    }

    public function test_prevents_cross_client_response_cache_sharing(): void
    {
        $clientA = ApiClient::create([
            'name' => 'Sistem PTSP',
            'api_key' => 'pm_live_11111111_ptsp_secret_key_123456789012345678',
            'scopes' => ['students:read'],
            'is_active' => true,
        ]);

        $clientB = ApiClient::create([
            'name' => 'Sistem SIPSMAD',
            'api_key' => 'pm_live_22222222_sipsmad_secret_key_1234567890123456',
            'scopes' => ['students:read'],
            'is_active' => true,
        ]);

        $sharedKey = 'idempotent-key-shared-003';
        $payload = ['item' => 'Surat Pengantar'];

        // Client A kirim request pertama
        $resA = $this->withHeaders([
            'X-API-KEY' => 'pm_live_11111111_ptsp_secret_key_123456789012345678',
            'Idempotency-Key' => $sharedKey,
        ])->postJson('/api/v1/test-idempotent-write', $payload);

        $resA->assertStatus(201)
            ->assertHeaderMissing('X-Idempotent-Replay');

        $countA = $resA->json('call_count');

        // Client B kirim request dengan key dan payload yang sama persis
        $resB = $this->withHeaders([
            'X-API-KEY' => 'pm_live_22222222_sipsmad_secret_key_1234567890123456',
            'Idempotency-Key' => $sharedKey,
        ])->postJson('/api/v1/test-idempotent-write', $payload);

        // Client B BUKAN replay dari Client A, melainkan dieksekusi independen dalam namespace Client B
        $resB->assertStatus(201)
            ->assertHeaderMissing('X-Idempotent-Replay');

        $this->assertNotSame($countA, $resB->json('call_count'));
    }

    public function test_same_key_on_different_http_method_does_not_collide(): void
    {
        ApiClient::create([
            'name' => 'Sistem Klien A',
            'api_key' => 'pm_live_11111111_client_a_secret_key_12345678901234',
            'scopes' => ['students:read'],
            'is_active' => true,
        ]);

        $key = 'idempotent-key-method-test';

        // POST
        $this->withHeaders([
            'X-API-KEY' => 'pm_live_11111111_client_a_secret_key_12345678901234',
            'Idempotency-Key' => $key,
        ])->postJson('/api/v1/test-idempotent-write', ['action' => 'save'])
            ->assertStatus(201);

        // PUT dengan key yang sama -> metode HTTP berbeda, tidak boleh tertukar dengan cache POST
        $putResponse = $this->withHeaders([
            'X-API-KEY' => 'pm_live_11111111_client_a_secret_key_12345678901234',
            'Idempotency-Key' => $key,
        ])->putJson('/api/v1/test-idempotent-write', ['action' => 'save']);

        $putResponse->assertStatus(200)
            ->assertHeaderMissing('X-Idempotent-Replay')
            ->assertJson([
                'success' => true,
                'method' => 'PUT',
            ]);
    }

    public function test_get_requests_ignore_idempotency_key_header(): void
    {
        ApiClient::create([
            'name' => 'Sistem Klien A',
            'api_key' => 'pm_live_11111111_client_a_secret_key_12345678901234',
            'scopes' => ['students:read'],
            'is_active' => true,
        ]);

        $this->withHeaders([
            'X-API-KEY' => 'pm_live_11111111_client_a_secret_key_12345678901234',
            'Idempotency-Key' => 'read-key-123',
        ])->getJson('/api/v1/test-idempotent-read')
            ->assertStatus(200)
            ->assertHeaderMissing('X-Idempotent-Replay');
    }
}
