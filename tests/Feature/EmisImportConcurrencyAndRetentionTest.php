<?php

namespace Tests\Feature;

use App\Models\EmisGtkStaging;
use App\Models\EmisImportSession;
use App\Models\EmisImportSessionPage;
use App\Models\IntegrationLog;
use App\Models\IntegrationToken;
use App\Models\User;
use App\Services\EmisImportSessionService;
use App\Services\IntegrationTokenService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class EmisImportConcurrencyAndRetentionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<int, string>|null  $abilities
     * @return array<string, string>
     */
    private function tokenHeaders(string $purpose = 'emis_gtk', ?array $abilities = ['staging:write']): array
    {
        $operator = User::factory()->create(['role' => 'operator']);
        $plain = app(IntegrationTokenService::class)
            ->issue($operator, $purpose, 'concurrency-test', null, $abilities)['plain_token'];

        return ['X-Integration-Token' => $plain];
    }

    /**
     * @return array<string, mixed>
     */
    private function gtkRow(string $id, ?string $name = null): array
    {
        return [
            'name' => $name ?? 'GTK '.strtoupper($id),
            'uuid' => $id,
            'active' => true,
        ];
    }

    public function test_payload_hash_is_key_order_independent_for_maps_but_preserves_list_order(): void
    {
        $service = app(EmisImportSessionService::class);

        $hashMapOrderA = $service->computePayloadHash([
            ['name' => 'BUDI SANTOSO', 'uuid' => 'u-1', 'meta' => ['a' => 1, 'b' => 2]],
            ['name' => 'SITI AMINAH', 'uuid' => 'u-2', 'meta' => ['b' => 2, 'a' => 1]],
        ]);
        $hashMapOrderB = $service->computePayloadHash([
            ['uuid' => 'u-1', 'meta' => ['b' => 2, 'a' => 1], 'name' => 'BUDI SANTOSO'],
            ['meta' => ['a' => 1, 'b' => 2], 'uuid' => 'u-2', 'name' => 'SITI AMINAH'],
        ]);
        $hashDifferentListOrder = $service->computePayloadHash([
            ['name' => 'SITI AMINAH', 'uuid' => 'u-2', 'meta' => ['a' => 1, 'b' => 2]],
            ['name' => 'BUDI SANTOSO', 'uuid' => 'u-1', 'meta' => ['a' => 1, 'b' => 2]],
        ]);

        $this->assertSame($hashMapOrderA, $hashMapOrderB);
        $this->assertNotSame($hashMapOrderA, $hashDifferentListOrder);

        $headers = $this->tokenHeaders();
        $first = $this->postJson('/api/emis-gtk/import', [
            'page' => 1,
            'total_pages' => 3,
            'data' => [
                ['name' => 'BUDI SANTOSO', 'uuid' => 'u-1', 'active' => true],
                ['name' => 'SITI AMINAH', 'uuid' => 'u-2', 'active' => true],
            ],
        ], $headers)->assertOk();

        $sid = $first->json('import_session_id');

        // Pengiriman ulang halaman 1 dengan urutan key JSON berbeda dianggap idempoten
        $replay = $this->postJson('/api/emis-gtk/import', [
            'import_session_id' => $sid,
            'page' => 1,
            'total_pages' => 3,
            'data' => [
                ['uuid' => 'u-1', 'active' => true, 'name' => 'BUDI SANTOSO'],
                ['active' => true, 'uuid' => 'u-2', 'name' => 'SITI AMINAH'],
            ],
        ], $headers)->assertOk();

        $replay->assertJsonPath('idempotent_replay', true)
            ->assertJsonPath('received_pages', 1);

        // Pengiriman ulang halaman 1 dengan urutan baris berbeda atau nilai berbeda ditolak IMPORT_PAGE_CONFLICT
        $this->postJson('/api/emis-gtk/import', [
            'import_session_id' => $sid,
            'page' => 1,
            'total_pages' => 3,
            'data' => [
                ['name' => 'SITI AMINAH', 'uuid' => 'u-2', 'active' => true],
                ['name' => 'BUDI SANTOSO', 'uuid' => 'u-1', 'active' => true],
            ],
        ], $headers)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'IMPORT_PAGE_CONFLICT');

        $this->postJson('/api/emis-gtk/import', [
            'import_session_id' => $sid,
            'page' => 1,
            'total_pages' => 3,
            'data' => [
                ['name' => 'DATA BERUBAH', 'uuid' => 'u-999', 'active' => true],
            ],
        ], $headers)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'IMPORT_PAGE_CONFLICT');

        $session = EmisImportSession::findOrFail($sid);
        $this->assertSame(EmisImportSession::STATUS_OPEN, $session->status);
        $this->assertSame(1, $session->received_pages);
        $this->assertSame(2, $session->received_rows);
        $this->assertCount(2, EmisImportSessionPage::where('session_id', $sid)->where('page', 1)->firstOrFail()->payload);
    }

    public function test_total_count_metadata_mismatch_and_strict_contract(): void
    {
        $headers = $this->tokenHeaders();
        EmisGtkStaging::create([
            'emis_gtk_id' => 'old-safe',
            'full_name_with_titles' => 'STAGING LAMA AMAN',
            'status' => 'pending',
            'source' => 'bookmarklet',
            'raw_data' => [],
        ]);

        // Sesi tanpa total_count menolak halaman 2 yang tiba-tiba mengirim total_count
        $sidNullCount = $this->postJson('/api/emis-gtk/import', [
            'page' => 1,
            'total_pages' => 2,
            'data' => [$this->gtkRow('n-1')],
        ], $headers)->assertOk()->json('import_session_id');

        $this->postJson('/api/emis-gtk/import', [
            'import_session_id' => $sidNullCount,
            'page' => 2,
            'total_pages' => 2,
            'total_count' => 2,
            'data' => [$this->gtkRow('n-2')],
        ], $headers)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'IMPORT_SESSION_MISMATCH');

        // Sesi dengan total_count=4 menolak halaman 2 yang mengirim total_count=3
        $sidWithCount = $this->postJson('/api/emis-gtk/import', [
            'page' => 1,
            'total_pages' => 2,
            'total_count' => 4,
            'data' => [$this->gtkRow('c-1'), $this->gtkRow('c-2')],
        ], $headers)->assertOk()->json('import_session_id');

        $this->postJson('/api/emis-gtk/import', [
            'import_session_id' => $sidWithCount,
            'page' => 2,
            'total_pages' => 2,
            'total_count' => 3,
            'data' => [$this->gtkRow('c-3')],
        ], $headers)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'IMPORT_SESSION_MISMATCH');

        // Semua halaman masuk (2/2) namun jumlah item 3 != total_count 4 -> gagal tanpa menyentuh staging lama
        $this->postJson('/api/emis-gtk/import', [
            'import_session_id' => $sidWithCount,
            'page' => 2,
            'total_pages' => 2,
            'total_count' => 4,
            'data' => [$this->gtkRow('c-3')],
        ], $headers)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'IMPORT_COUNT_MISMATCH');

        $failedSession = EmisImportSession::findOrFail($sidWithCount);
        $this->assertSame(EmisImportSession::STATUS_FAILED, $failedSession->status);
        $this->assertNotNull($failedSession->failed_at);
        $this->assertDatabaseHas('emis_gtk_stagings', ['emis_gtk_id' => 'old-safe']);
        $this->assertSame(0, EmisGtkStaging::where('import_session_id', $sidWithCount)->count());
    }

    public function test_request_during_active_finalizing_and_stale_finalizing_lease_recovery(): void
    {
        $headers = $this->tokenHeaders();
        $sid = $this->postJson('/api/emis-gtk/import', [
            'page' => 1,
            'total_pages' => 2,
            'total_count' => 2,
            'data' => [$this->gtkRow('r-1')],
        ], $headers)->assertOk()->json('import_session_id');

        // Simulasikan sesi sedang dalam proses finalisasi aktif (lease belum kedaluwarsa)
        EmisImportSession::whereKey($sid)->update([
            'status' => EmisImportSession::STATUS_FINALIZING,
            'finalizing_at' => now(),
        ]);

        $this->postJson('/api/emis-gtk/import', [
            'import_session_id' => $sid,
            'page' => 2,
            'total_pages' => 2,
            'total_count' => 2,
            'data' => [$this->gtkRow('r-2')],
        ], $headers)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'IMPORT_FINALIZING')
            ->assertJsonPath('status', 'finalizing');

        // Simulasikan worker sebelumnya crash sehingga lease finalizing kedaluwarsa (> 300 detik)
        EmisImportSession::whereKey($sid)->update([
            'finalizing_at' => now()->subMinutes(10),
        ]);

        // Retry klien setelah timeout memulihkan sesi dan menyelesaikan finalisasi tepat satu kali
        $recovered = $this->postJson('/api/emis-gtk/import', [
            'import_session_id' => $sid,
            'page' => 2,
            'total_pages' => 2,
            'total_count' => 2,
            'data' => [$this->gtkRow('r-2')],
        ], $headers)->assertOk();

        $recovered->assertJsonPath('complete', true)
            ->assertJsonPath('saved', 2)
            ->assertJsonPath('status', 'completed');

        // Retry ulang setelah completed mengembalikan 409 deterministik beserta saved_rows tanpa duplikasi finalisasi
        $this->postJson('/api/emis-gtk/import', [
            'import_session_id' => $sid,
            'page' => 2,
            'total_pages' => 2,
            'total_count' => 2,
            'data' => [$this->gtkRow('r-2')],
        ], $headers)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'IMPORT_SESSION_CLOSED')
            ->assertJsonPath('complete', true)
            ->assertJsonPath('saved', 2);

        $this->assertSame(
            1,
            IntegrationLog::where('event', 'staging.replaced')->count(),
            'Finalisasi penggantian staging hanya boleh terekam tepat satu kali.'
        );
    }

    public function test_scheduled_cleanup_enforces_retention_tiers_is_idempotent_and_never_deletes_active_finalizing(): void
    {
        Artisan::call('schedule:list');
        $this->assertStringContainsString('emis:import-sessions:cleanup', Artisan::output());

        $service = app(EmisImportSessionService::class);
        $samplePayload = [$this->gtkRow('cleanup-sample')];
        $sampleHash = $service->computePayloadHash($samplePayload);

        // 1) Open overdue -> expired + halaman dihapus
        $openOverdue = EmisImportSession::factory()->create([
            'status' => EmisImportSession::STATUS_OPEN,
            'expires_at' => now()->subMinutes(5),
        ]);
        EmisImportSessionPage::create([
            'session_id' => $openOverdue->id,
            'page' => 1,
            'items_count' => 1,
            'payload_hash' => $sampleHash,
            'payload' => $samplePayload,
        ]);

        // 2) Open aktif -> tidak disentuh
        $openActive = EmisImportSession::factory()->create([
            'status' => EmisImportSession::STATUS_OPEN,
            'expires_at' => now()->addHour(),
        ]);
        EmisImportSessionPage::create([
            'session_id' => $openActive->id,
            'page' => 1,
            'items_count' => 1,
            'payload_hash' => $sampleHash,
            'payload' => $samplePayload,
        ]);

        // 3) Finalizing aktif (bahkan ketika expires_at baru saja lewat saat finalisasi sedang jalan!) -> DILARANG disentuh
        $activeFinalizing = EmisImportSession::factory()->create([
            'status' => EmisImportSession::STATUS_FINALIZING,
            'finalizing_at' => now()->subSeconds(20),
            'expires_at' => now()->subMinute(),
        ]);
        EmisImportSessionPage::create([
            'session_id' => $activeFinalizing->id,
            'page' => 1,
            'items_count' => 1,
            'payload_hash' => $sampleHash,
            'payload' => $samplePayload,
        ]);

        // 4) Failed baru (< 24 jam) -> payload halaman TETAP dipertahankan sementara
        $failedRecent = EmisImportSession::factory()->create([
            'status' => EmisImportSession::STATUS_FAILED,
            'failed_at' => now()->subHours(2),
            'expires_at' => now()->subHour(),
        ]);
        EmisImportSessionPage::create([
            'session_id' => $failedRecent->id,
            'page' => 1,
            'items_count' => 1,
            'payload_hash' => $sampleHash,
            'payload' => $samplePayload,
        ]);

        // 5) Failed lama (> 24 jam, < 30 hari) -> payload halaman dihapus, namun metadata sesi dipertahankan
        $failedPayloadExpired = EmisImportSession::factory()->create([
            'status' => EmisImportSession::STATUS_FAILED,
            'failed_at' => now()->subHours(30),
            'expires_at' => now()->subHours(31),
        ]);
        EmisImportSessionPage::create([
            'session_id' => $failedPayloadExpired->id,
            'page' => 1,
            'items_count' => 1,
            'payload_hash' => $sampleHash,
            'payload' => $samplePayload,
        ]);

        // 6) Completed dalam masa retensi audit (< 30 hari) -> metadata tetap dipertahankan
        $completedWithinRetention = EmisImportSession::factory()->create([
            'status' => EmisImportSession::STATUS_COMPLETED,
            'completed_at' => now()->subDays(10),
        ]);

        // 7) Completed & Expired melewati masa retensi audit (> 30 hari) -> metadata dihapus
        $completedOld = EmisImportSession::factory()->create([
            'status' => EmisImportSession::STATUS_COMPLETED,
            'completed_at' => now()->subDays(35),
        ]);
        $expiredOld = EmisImportSession::factory()->create([
            'status' => EmisImportSession::STATUS_EXPIRED,
            'expires_at' => now()->subDays(35),
        ]);

        $this->artisan('emis:import-sessions:cleanup', ['--batch' => 2])->assertExitCode(0);

        $this->assertSame(EmisImportSession::STATUS_EXPIRED, $openOverdue->fresh()->status);
        $this->assertSame(0, EmisImportSessionPage::where('session_id', $openOverdue->id)->count());

        $this->assertSame(EmisImportSession::STATUS_OPEN, $openActive->fresh()->status);
        $this->assertSame(1, EmisImportSessionPage::where('session_id', $openActive->id)->count());

        $this->assertSame(EmisImportSession::STATUS_FINALIZING, $activeFinalizing->fresh()->status);
        $this->assertSame(1, EmisImportSessionPage::where('session_id', $activeFinalizing->id)->count());

        $this->assertSame(EmisImportSession::STATUS_FAILED, $failedRecent->fresh()->status);
        $this->assertSame(1, EmisImportSessionPage::where('session_id', $failedRecent->id)->count());

        $this->assertNotNull($failedPayloadExpired->fresh());
        $this->assertSame(0, EmisImportSessionPage::where('session_id', $failedPayloadExpired->id)->count());

        $this->assertNotNull($completedWithinRetention->fresh());
        $this->assertNull($completedOld->fresh());
        $this->assertNull($expiredOld->fresh());

        // Eksekusi ulang langsung bersifat idempoten (0 perubahan)
        $secondStats = $service->cleanupSessions(2);
        $this->assertSame(0, array_sum($secondStats));
    }

    public function test_finalization_midway_integrity_failure_rolls_back_staging_and_redacts_pii(): void
    {
        Log::spy();

        $headers = $this->tokenHeaders();
        EmisGtkStaging::create([
            'emis_gtk_id' => 'old-pending-1',
            'full_name_with_titles' => 'STAGING LAMA UTUH',
            'status' => 'pending',
            'source' => 'bookmarklet',
            'raw_data' => [],
        ]);
        EmisGtkStaging::create([
            'emis_gtk_id' => 'committed-keep-1',
            'full_name_with_titles' => 'HISTORI COMMITTED UTUH',
            'status' => 'committed',
            'source' => 'bookmarklet',
            'raw_data' => [],
        ]);

        $sid = $this->postJson('/api/emis-gtk/import', [
            'page' => 1,
            'total_pages' => 2,
            'data' => [[
                'name' => 'GTK HALAMAN SATU',
                'uuid' => 'new-p1',
                'nik' => '3201123456780001',
                'nip' => '198501012010011009',
            ]],
        ], $headers)->assertOk()->json('import_session_id');

        // Rusak hash halaman 1 di penyimpanan sementara untuk memicu kegagalan saat verifikasi finalisasi
        EmisImportSessionPage::where('session_id', $sid)
            ->where('page', 1)
            ->update(['payload_hash' => str_repeat('f', 64)]);

        $this->postJson('/api/emis-gtk/import', [
            'import_session_id' => $sid,
            'page' => 2,
            'total_pages' => 2,
            'data' => [[
                'name' => 'GTK HALAMAN DUA',
                'uuid' => 'new-p2',
                'nuptk' => '9876543210123456',
            ]],
        ], $headers)
            ->assertStatus(500)
            ->assertJsonPath('error.code', 'IMPORT_FINALIZE_FAILED');

        // Staging lama dan histori committed tetap utuh; data sesi gagal tidak masuk staging
        $this->assertDatabaseHas('emis_gtk_stagings', ['emis_gtk_id' => 'old-pending-1', 'status' => 'pending']);
        $this->assertDatabaseHas('emis_gtk_stagings', ['emis_gtk_id' => 'committed-keep-1', 'status' => 'committed']);
        $this->assertSame(0, EmisGtkStaging::where('import_session_id', $sid)->count());

        $session = EmisImportSession::findOrFail($sid);
        $this->assertSame(EmisImportSession::STATUS_FAILED, $session->status);
        $this->assertNotNull($session->failure_reason);
        $this->assertStringNotContainsString('3201123456780001', (string) $session->failure_reason);
        $this->assertStringNotContainsString('198501012010011009', (string) $session->failure_reason);
        $this->assertStringNotContainsString('9876543210123456', (string) $session->failure_reason);

        // Payload halaman tetap dipertahankan sementara pada sesi failed untuk audit/pemulihan
        $this->assertSame(2, EmisImportSessionPage::where('session_id', $sid)->count());
    }

    public function test_clear_staging_respects_active_staging_mutation_lock(): void
    {
        $headers = $this->tokenHeaders('emis_gtk', ['staging:write', 'sync:trigger']);
        Cache::put('emis:token:gtk', ['c' => 'cookie'], now()->addHour());

        EmisGtkStaging::create([
            'emis_gtk_id' => 'protected-pending',
            'full_name_with_titles' => 'SEDANG DIPROSES',
            'status' => 'pending',
            'source' => 'bookmarklet',
            'raw_data' => [],
        ]);

        $lock = Cache::lock(EmisImportSessionService::STAGING_MUTATION_LOCK_KEY, 60);
        $this->assertTrue($lock->get());

        try {
            $this->postJson('/api/emis-gtk/pull', ['clear_staging' => true], $headers)
                ->assertStatus(409)
                ->assertJsonPath('error.code', 'IMPORT_IN_PROGRESS');

            $this->assertDatabaseHas('emis_gtk_stagings', ['emis_gtk_id' => 'protected-pending']);
        } finally {
            $lock->release();
        }
    }

    public function test_parallel_two_final_pages_and_duplicate_last_page_execute_finalization_once(): void
    {
        $this->runWithIsolatedParallelEnvironment(function (callable $dispatchParallel): void {
            $operator = User::factory()->create(['role' => 'operator']);
            $plainToken = app(IntegrationTokenService::class)
                ->issue($operator, 'emis_gtk', 'parallel-session', null, ['staging:write'])['plain_token'];

            EmisGtkStaging::create([
                'emis_gtk_id' => 'old-before-parallel',
                'full_name_with_titles' => 'STAGING SEBELUMNYA',
                'status' => 'pending',
                'source' => 'bookmarklet',
                'raw_data' => [],
            ]);
            EmisGtkStaging::create([
                'emis_gtk_id' => 'committed-parallel-safe',
                'full_name_with_titles' => 'COMMITTED TETAP AMAN',
                'status' => 'committed',
                'source' => 'bookmarklet',
                'raw_data' => [],
            ]);

            // Skenario 1: Halaman 1 sudah masuk; Proses A mengirim Halaman 2 & Proses B mengirim Halaman 3 secara paralel
            $initOne = $dispatchParallel([
                [
                    'token' => $plainToken,
                    'payload' => ['page' => 1, 'total_pages' => 3, 'total_count' => 3, 'data' => [$this->gtkRow('p3-1')]],
                ],
            ])[0];
            $this->assertSame(200, $initOne['status']);
            $sidThreePages = $initOne['json']['import_session_id'];

            $raceFinalTwoPages = $dispatchParallel([
                [
                    'token' => $plainToken,
                    'payload' => [
                        'import_session_id' => $sidThreePages,
                        'page' => 2,
                        'total_pages' => 3,
                        'total_count' => 3,
                        'data' => [$this->gtkRow('p3-2')],
                    ],
                ],
                [
                    'token' => $plainToken,
                    'payload' => [
                        'import_session_id' => $sidThreePages,
                        'page' => 3,
                        'total_pages' => 3,
                        'total_count' => 3,
                        'data' => [$this->gtkRow('p3-3')],
                    ],
                ],
            ]);

            $this->assertSame([200, 200], array_column($raceFinalTwoPages, 'status'));
            $completedFlags = array_map(fn (array $r) => (bool) ($r['json']['complete'] ?? false), $raceFinalTwoPages);
            sort($completedFlags);
            $this->assertSame([false, true], $completedFlags, 'Tepat satu proses paralel yang boleh mengeksekusi finalisasi.');

            $sessionThree = EmisImportSession::findOrFail($sidThreePages);
            $this->assertSame(EmisImportSession::STATUS_COMPLETED, $sessionThree->status);
            $this->assertSame(3, $sessionThree->received_pages);
            $this->assertSame(3, $sessionThree->received_rows);
            $this->assertSame(3, EmisGtkStaging::where('import_session_id', $sidThreePages)->where('status', 'pending')->count());
            $this->assertDatabaseMissing('emis_gtk_stagings', ['emis_gtk_id' => 'old-before-parallel']);
            $this->assertDatabaseHas('emis_gtk_stagings', ['emis_gtk_id' => 'committed-parallel-safe', 'status' => 'committed']);
            $this->assertSame(
                1,
                IntegrationLog::where('event', 'staging.replaced')
                    ->get()
                    ->filter(fn (IntegrationLog $log) => ($log->context['import_session_id'] ?? null) === $sidThreePages)
                    ->count()
            );

            // Skenario 2: Dua proses paralel mengirim halaman terakhir yang SAMA (duplicate last page race)
            $initDup = $dispatchParallel([
                [
                    'token' => $plainToken,
                    'payload' => ['page' => 1, 'total_pages' => 2, 'total_count' => 2, 'data' => [$this->gtkRow('dup-1')]],
                ],
            ])[0];
            $sidDup = $initDup['json']['import_session_id'];

            $raceDupLastPage = $dispatchParallel([
                [
                    'token' => $plainToken,
                    'payload' => [
                        'import_session_id' => $sidDup,
                        'page' => 2,
                        'total_pages' => 2,
                        'total_count' => 2,
                        'data' => [$this->gtkRow('dup-2')],
                    ],
                ],
                [
                    'token' => $plainToken,
                    'payload' => [
                        'import_session_id' => $sidDup,
                        'page' => 2,
                        'total_pages' => 2,
                        'total_count' => 2,
                        'data' => [$this->gtkRow('dup-2')],
                    ],
                ],
            ]);

            $statusesDup = array_column($raceDupLastPage, 'status');
            sort($statusesDup);
            $this->assertSame([200, 409], $statusesDup);

            $conflictItem = collect($raceDupLastPage)->firstWhere('status', 409);
            $this->assertContains(
                $conflictItem['json']['error']['code'] ?? null,
                ['IMPORT_FINALIZING', 'IMPORT_SESSION_CLOSED']
            );
            $this->assertSame(2, EmisGtkStaging::where('import_session_id', $sidDup)->where('status', 'pending')->count());
            $this->assertSame(
                1,
                IntegrationLog::where('event', 'staging.replaced')
                    ->get()
                    ->filter(fn (IntegrationLog $log) => ($log->context['import_session_id'] ?? null) === $sidDup)
                    ->count()
            );
        });
    }

    public function test_parallel_conflicting_same_page_and_two_sessions_finalizing_simultaneously(): void
    {
        $this->runWithIsolatedParallelEnvironment(function (callable $dispatchParallel): void {
            $opA = User::factory()->create(['role' => 'operator']);
            $opB = User::factory()->create(['role' => 'operator']);
            $tokenA = app(IntegrationTokenService::class)
                ->issue($opA, 'emis_gtk', 'parallel-A', null, ['staging:write'])['plain_token'];
            $tokenB = app(IntegrationTokenService::class)
                ->issue($opB, 'emis_gtk', 'parallel-B', null, ['staging:write'])['plain_token'];

            // Skenario 1: Konflik payload bersamaan pada halaman 2 di sesi yang sama
            $initConflict = $dispatchParallel([
                [
                    'token' => $tokenA,
                    'payload' => ['page' => 1, 'total_pages' => 3, 'data' => [$this->gtkRow('cf-1')]],
                ],
            ])[0];
            $sidConflict = $initConflict['json']['import_session_id'];

            $raceConflict = $dispatchParallel([
                [
                    'token' => $tokenA,
                    'payload' => [
                        'import_session_id' => $sidConflict,
                        'page' => 2,
                        'total_pages' => 3,
                        'data' => [$this->gtkRow('cf-2-version-a')],
                    ],
                ],
                [
                    'token' => $tokenA,
                    'payload' => [
                        'import_session_id' => $sidConflict,
                        'page' => 2,
                        'total_pages' => 3,
                        'data' => [$this->gtkRow('cf-2-version-b')],
                    ],
                ],
            ]);

            $conflictStatuses = array_column($raceConflict, 'status');
            sort($conflictStatuses);
            $this->assertSame([200, 409], $conflictStatuses);
            $rejected = collect($raceConflict)->firstWhere('status', 409);
            $this->assertSame('IMPORT_PAGE_CONFLICT', $rejected['json']['error']['code'] ?? null);
            $this->assertSame(2, EmisImportSession::findOrFail($sidConflict)->received_pages);

            // Skenario 2: Dua sesi berbeda (Token A dan Token B) menyelesaikan finalisasi pada saat bersamaan
            $sidA = $dispatchParallel([
                ['token' => $tokenA, 'payload' => ['page' => 1, 'total_pages' => 2, 'data' => [$this->gtkRow('sa-1')]]],
            ])[0]['json']['import_session_id'];

            $sidB = $dispatchParallel([
                ['token' => $tokenB, 'payload' => ['page' => 1, 'total_pages' => 2, 'data' => [$this->gtkRow('sb-1')]]],
            ])[0]['json']['import_session_id'];

            $simultaneousFinish = $dispatchParallel([
                [
                    'token' => $tokenA,
                    'payload' => ['import_session_id' => $sidA, 'page' => 2, 'total_pages' => 2, 'data' => [$this->gtkRow('sa-2')]],
                ],
                [
                    'token' => $tokenB,
                    'payload' => ['import_session_id' => $sidB, 'page' => 2, 'total_pages' => 2, 'data' => [$this->gtkRow('sb-2')]],
                ],
            ]);

            $this->assertSame([200, 200], array_column($simultaneousFinish, 'status'));
            $this->assertSame(EmisImportSession::STATUS_COMPLETED, EmisImportSession::findOrFail($sidA)->status);
            $this->assertSame(EmisImportSession::STATUS_COMPLETED, EmisImportSession::findOrFail($sidB)->status);

            // Staging pending akhir harus utuh berasal dari tepat satu sesi pemenang terakhir (2 baris, tidak tercampur)
            $pendingRows = EmisGtkStaging::where('status', 'pending')->get();
            $this->assertCount(2, $pendingRows);
            $this->assertSame(1, $pendingRows->pluck('import_session_id')->unique()->count());
            $this->assertContains($pendingRows->first()->import_session_id, [$sidA, $sidB]);
        });
    }

    /**
     * Jalankan callback pengujian dengan eksekutor HTTP multi-proses OS nyata yang kompatibel
     * terhadap SQLite in-memory (dialihkan sementara ke file WAL terisolasi) dan MySQL 8.
     */
    private function runWithIsolatedParallelEnvironment(callable $scenario): void
    {
        $driver = DB::connection()->getDriverName();
        $tempSqlitePath = null;
        $originalSqlitePdo = null;
        $originalTransactionLevel = 0;
        $tempCacheDir = sys_get_temp_dir().'/pintu_emis_import_cache_'.Str::lower(Str::random(10));
        File::ensureDirectoryExists($tempCacheDir);

        $runnerScriptPath = sys_get_temp_dir().'/pintu_emis_import_runner_'.Str::lower(Str::random(10)).'.php';
        file_put_contents($runnerScriptPath, $this->buildHttpRunnerScript());

        if ($driver === 'sqlite' && DB::connection()->getDatabaseName() === ':memory:') {
            $originalConn = DB::connection('sqlite');
            $originalSqlitePdo = $originalConn->getPdo();
            $originalTransactionLevel = $originalConn->transactionLevel();

            $tempSqlitePath = sys_get_temp_dir().'/pintu_parallel_emis_'.Str::lower(Str::random(12)).'.sqlite';
            touch($tempSqlitePath);

            config(['database.connections.sqlite.database' => $tempSqlitePath]);
            DB::purge('sqlite');
            DB::reconnect('sqlite');

            DB::statement('PRAGMA journal_mode = WAL');
            DB::statement('PRAGMA busy_timeout = 5000');

            Artisan::call('migrate', ['--force' => true]);
            $this->seed(RolesAndPermissionsSeeder::class);
        } elseif ($driver === 'mysql') {
            while (DB::connection()->transactionLevel() > 0) {
                DB::connection()->commit();
            }
        }

        $existingUserIds = $driver === 'mysql' ? User::query()->pluck('id')->all() : [];

        $dbConnection = (string) config('database.default');
        $dbDatabase = (string) ($tempSqlitePath ?? config("database.connections.{$dbConnection}.database"));
        $parentCacheStore = (string) config('cache.default');
        $effectiveCacheStore = $parentCacheStore === 'redis' ? 'redis' : 'file';
        $redisClient = (string) config('database.redis.client', 'phpredis');

        $dispatchParallel = function (array $requests) use ($runnerScriptPath, $dbConnection, $dbDatabase, $effectiveCacheStore, $tempCacheDir, $redisClient): array {
            /** @var array<int, Process> $processes */
            $processes = [];
            /** @var array<int, string> $payloadFiles */
            $payloadFiles = [];

            foreach ($requests as $idx => $req) {
                $payloadFile = sys_get_temp_dir().'/pintu_req_'.Str::lower(Str::random(12))."_{$idx}.json";
                file_put_contents($payloadFile, json_encode($req, JSON_THROW_ON_ERROR));
                $payloadFiles[$idx] = $payloadFile;

                $proc = new Process([
                    PHP_BINARY,
                    $runnerScriptPath,
                    base_path(),
                    $dbConnection,
                    $dbDatabase,
                    $effectiveCacheStore,
                    $tempCacheDir,
                    $redisClient,
                    $payloadFile,
                ]);
                $proc->setTimeout(60);
                $processes[$idx] = $proc;
            }

            foreach ($processes as $proc) {
                $proc->start();
            }

            $results = [];
            foreach ($processes as $idx => $proc) {
                $proc->wait();
                @unlink($payloadFiles[$idx]);

                $this->assertTrue(
                    $proc->isSuccessful(),
                    'Proses HTTP paralel gagal: '.$proc->getErrorOutput().' '.$proc->getOutput()
                );

                $decoded = json_decode(trim($proc->getOutput()), true);
                $this->assertIsArray($decoded, 'Output proses paralel tidak valid: '.$proc->getOutput());
                $results[$idx] = $decoded;
            }

            DB::connection()->disconnect();
            DB::connection()->reconnect();

            return $results;
        };

        try {
            $scenario($dispatchParallel);
        } finally {
            @unlink($runnerScriptPath);
            File::deleteDirectory($tempCacheDir);

            if ($driver === 'mysql') {
                EmisImportSessionPage::query()->delete();
                EmisImportSession::query()->delete();
                EmisGtkStaging::query()->delete();
                IntegrationLog::query()->delete();
                IntegrationToken::query()->delete();
                User::query()->whereNotIn('id', $existingUserIds)->delete();
                DB::connection()->beginTransaction();
            } elseif ($tempSqlitePath !== null) {
                DB::disconnect('sqlite');
                config(['database.connections.sqlite.database' => ':memory:']);
                DB::purge('sqlite');

                if ($originalSqlitePdo !== null) {
                    $restoredConn = DB::connection('sqlite');
                    $restoredConn->setPdo($originalSqlitePdo);

                    $txProp = new \ReflectionProperty(Connection::class, 'transactions');
                    $txProp->setValue($restoredConn, $originalTransactionLevel);
                }

                @unlink($tempSqlitePath);
                @unlink($tempSqlitePath.'-wal');
                @unlink($tempSqlitePath.'-shm');
            }
        }
    }

    private function buildHttpRunnerScript(): string
    {
        return <<<'PHP'
<?php
$basePath = $argv[1];
$dbConnection = $argv[2];
$dbDatabase = $argv[3];
$cacheStore = $argv[4];
$cacheDir = $argv[5];
$redisClient = $argv[6];
$payloadFile = $argv[7];

require $basePath.'/vendor/autoload.php';
$app = require $basePath.'/bootstrap/app.php';
$consoleKernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$consoleKernel->bootstrap();

config([
    'database.default' => $dbConnection,
    "database.connections.{$dbConnection}.database" => $dbDatabase,
    'database.redis.client' => $redisClient,
    'cache.default' => $cacheStore,
    'cache.stores.file.path' => $cacheDir,
    'cache.stores.file.lock_path' => $cacheDir,
]);

Illuminate\Support\Facades\DB::purge($dbConnection);
Illuminate\Support\Facades\DB::reconnect($dbConnection);
Illuminate\Support\Facades\Cache::forgetDriver($cacheStore);

if ($dbConnection === 'sqlite') {
    Illuminate\Support\Facades\DB::statement('PRAGMA journal_mode = WAL');
    Illuminate\Support\Facades\DB::statement('PRAGMA busy_timeout = 5000');
}

$spec = json_decode((string) file_get_contents($payloadFile), true, 512, JSON_THROW_ON_ERROR);
$bodyJson = json_encode($spec['payload'], JSON_THROW_ON_ERROR);

$httpKernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$request = Illuminate\Http\Request::create(
    '/api/emis-gtk/import',
    'POST',
    [],
    [],
    [],
    [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_INTEGRATION_TOKEN' => $spec['token'],
    ],
    $bodyJson
);

$response = $httpKernel->handle($request);
$httpKernel->terminate($request, $response);

echo json_encode([
    'status' => $response->getStatusCode(),
    'json' => json_decode((string) $response->getContent(), true),
]);
PHP;
    }
}
