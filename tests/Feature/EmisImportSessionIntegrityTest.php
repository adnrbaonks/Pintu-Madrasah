<?php

namespace Tests\Feature;

use App\Models\EmisGtkStaging;
use App\Models\EmisImportSession;
use App\Models\EmisStaging;
use App\Models\EmisUpdateTask;
use App\Models\User;
use App\Services\IntegrationTokenService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class EmisImportSessionIntegrityTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function h(string $purpose, ?array $abilities, ?User $user = null): array
    {
        $user ??= User::factory()->create(['role' => 'operator']);
        $t = app(IntegrationTokenService::class)->issue($user, $purpose, 'T', null, $abilities);

        return ['X-Integration-Token' => $t['plain_token']];
    }

    private function row(string $id): array
    {
        return ['name' => 'Guru '.$id, 'uuid' => $id];
    }

    private function old(): void
    {
        EmisGtkStaging::create(['emis_gtk_id' => 'old-1', 'full_name_with_titles' => 'LAMA', 'status' => 'pending', 'source' => 'x', 'raw_data' => []]);
        EmisGtkStaging::create(['emis_gtk_id' => 'com-1', 'full_name_with_titles' => 'COMMITTED', 'status' => 'committed', 'source' => 'x', 'raw_data' => []]);
    }

    private function imp(array $h, array $ids, int $page, int $total, ?string $sid = null, ?int $count = null)
    {
        $body = ['data' => array_map(fn ($i) => $this->row($i), $ids), 'page' => $page, 'total_pages' => $total];
        if ($sid) {
            $body['import_session_id'] = $sid;
        }
        if ($count !== null) {
            $body['total_count'] = $count;
        }

        return $this->postJson('/api/emis-gtk/import', $body, $h);
    }

    public function test_first_page_returns_session_id_and_staging_replaced_only_when_all_pages_arrive(): void
    {
        $h = $this->h('emis_gtk', ['staging:write']);
        $this->old();

        $r1 = $this->imp($h, ['a'], 1, 2, null, 2)->assertOk()->assertJsonPath('complete', false);
        $sid = $r1->json('import_session_id');
        $this->assertNotEmpty($sid);
        $this->assertDatabaseHas('emis_gtk_stagings', ['emis_gtk_id' => 'old-1']);

        $this->imp($h, ['b'], 2, 2, $sid, 2)->assertOk()->assertJsonPath('complete', true)->assertJsonPath('saved', 2);
        $this->assertDatabaseMissing('emis_gtk_stagings', ['emis_gtk_id' => 'old-1']);
        $this->assertDatabaseHas('emis_gtk_stagings', ['emis_gtk_id' => 'com-1', 'status' => 'committed']);
        $this->assertSame(2, EmisGtkStaging::where('import_session_id', $sid)->count());
        $this->assertSame('completed', EmisImportSession::find($sid)->status);
        $this->assertDatabaseCount('emis_import_session_pages', 0);
    }

    public function test_later_page_without_session_id_is_rejected(): void
    {
        $h = $this->h('emis_gtk', ['staging:write']);
        $this->imp($h, ['a'], 2, 2)->assertStatus(422)->assertJsonPath('error.code', 'IMPORT_SESSION_REQUIRED');
        $this->assertSame(0, EmisGtkStaging::count());
    }

    public function test_last_page_first_out_of_order_and_duplicate_pages_are_idempotent(): void
    {
        $h = $this->h('emis_gtk', ['staging:write']);
        $this->old();
        $sid = $this->imp($h, ['a'], 1, 3)->assertOk()->json('import_session_id');

        $this->imp($h, ['c'], 3, 3, $sid)->assertOk()->assertJsonPath('complete', false);
        $this->assertDatabaseHas('emis_gtk_stagings', ['emis_gtk_id' => 'old-1']);
        $this->imp($h, ['c'], 3, 3, $sid)->assertOk()->assertJsonPath('received_pages', 2);
        $this->imp($h, ['a'], 1, 3, $sid)->assertOk()->assertJsonPath('received_pages', 2);
        $this->imp($h, ['b'], 2, 3, $sid)->assertOk()->assertJsonPath('complete', true);

        $this->assertSame(3, EmisGtkStaging::where('status', 'pending')->count());
        $this->assertDatabaseMissing('emis_gtk_stagings', ['emis_gtk_id' => 'old-1']);
        $this->imp($h, ['b'], 2, 3, $sid)->assertStatus(409)->assertJsonPath('error.code', 'IMPORT_SESSION_CLOSED');
        $this->assertSame(3, EmisGtkStaging::where('status', 'pending')->count());
    }

    public function test_two_concurrent_sessions_are_isolated_and_cannot_be_mixed(): void
    {
        $hA = $this->h('emis_gtk', ['staging:write']);
        $hB = $this->h('emis_gtk', ['staging:write']);
        $sa = $this->imp($hA, ['a1'], 1, 2)->json('import_session_id');
        $sb = $this->imp($hB, ['b1'], 1, 2)->json('import_session_id');
        $this->assertNotSame($sa, $sb);

        // B tidak boleh menulis ke sesi A
        $this->imp($hB, ['x'], 2, 2, $sa)->assertStatus(404)->assertJsonPath('error.code', 'IMPORT_SESSION_NOT_FOUND');
        $this->assertSame(0, EmisGtkStaging::count());

        $this->imp($hA, ['a2'], 2, 2, $sa)->assertOk()->assertJsonPath('complete', true);
        $this->imp($hB, ['b2'], 2, 2, $sb)->assertOk()->assertJsonPath('complete', true);
        // sesi terakhir selesai menjadi pemenang penggantian; data tiap sesi utuh (tidak tercampur per halaman)
        $this->assertSame(2, EmisGtkStaging::where('import_session_id', $sb)->count());
        $this->assertSame(0, EmisGtkStaging::where('import_session_id', $sa)->where('status', 'pending')->count());
    }

    public function test_total_pages_mismatch_and_page_beyond_total_are_rejected(): void
    {
        $h = $this->h('emis_gtk', ['staging:write']);
        $sid = $this->imp($h, ['a'], 1, 2)->json('import_session_id');
        $this->imp($h, ['b'], 2, 3, $sid)->assertStatus(422)->assertJsonPath('error.code', 'IMPORT_SESSION_MISMATCH');
        $this->imp($h, ['b'], 5, 2, $sid)->assertStatus(422)->assertJsonPath('error.code', 'INVALID_PAGE');
        $this->postJson('/api/emis-gtk/import', ['data' => [$this->row('z')], 'page' => 0], $h)->assertStatus(422);
    }

    public function test_count_mismatch_keeps_old_staging_and_allows_recovery(): void
    {
        $h = $this->h('emis_gtk', ['staging:write']);
        $this->old();
        $sid = $this->imp($h, ['a'], 1, 2, null, 5)->json('import_session_id');
        $this->imp($h, ['b'], 2, 2, $sid, 5)->assertStatus(422)->assertJsonPath('error.code', 'IMPORT_COUNT_MISMATCH');
        $this->assertDatabaseHas('emis_gtk_stagings', ['emis_gtk_id' => 'old-1']);
        $this->assertSame(0, EmisGtkStaging::where('import_session_id', $sid)->count());

        // pemulihan: halaman 2 dikirim ulang dengan data lengkap -> total_count benar
        $sid2 = $this->imp($h, ['a', 'b'], 1, 2, null, 3)->json('import_session_id');
        $this->imp($h, ['c'], 2, 2, $sid2, 3)->assertOk()->assertJsonPath('complete', true);
        $this->assertSame(3, EmisGtkStaging::where('status', 'pending')->count());
    }

    public function test_expired_session_is_rejected_and_never_replaces_staging(): void
    {
        $h = $this->h('emis_gtk', ['staging:write']);
        $this->old();
        $sid = $this->imp($h, ['a'], 1, 2)->json('import_session_id');
        EmisImportSession::whereKey($sid)->update(['expires_at' => now()->subMinute()]);

        $this->imp($h, ['b'], 2, 2, $sid)->assertStatus(410)->assertJsonPath('error.code', 'IMPORT_SESSION_EXPIRED');
        $this->assertSame('expired', EmisImportSession::find($sid)->status);
        $this->assertDatabaseHas('emis_gtk_stagings', ['emis_gtk_id' => 'old-1']);
        $this->assertDatabaseCount('emis_import_session_pages', 0);
    }

    public function test_abandoned_import_leaves_staging_untouched(): void
    {
        $h = $this->h('emis_gtk', ['staging:write']);
        $this->old();
        $this->imp($h, ['a'], 1, 3)->assertOk();
        $this->assertSame(1, EmisGtkStaging::where('status', 'pending')->count());
        $this->assertDatabaseHas('emis_gtk_stagings', ['emis_gtk_id' => 'old-1']);
        $this->assertDatabaseMissing('emis_gtk_stagings', ['emis_gtk_id' => 'a']);
    }

    public function test_pull_clear_staging_does_not_break_import_in_progress(): void
    {
        $h = $this->h('emis_gtk', ['staging:write', 'sync:trigger']);
        Cache::put('emis:token:gtk', ['c' => 'cookie'], now()->addHour());
        $sid = $this->imp($h, ['a'], 1, 2)->json('import_session_id');

        $this->postJson('/api/emis-gtk/pull', ['clear_staging' => true], $h)->assertOk();
        $this->imp($h, ['b'], 2, 2, $sid)->assertOk()->assertJsonPath('complete', true);
        $this->assertSame(2, EmisGtkStaging::where('import_session_id', $sid)->count());
    }

    public function test_authorization_gaps_on_destructive_and_forbidden_actions(): void
    {
        Cache::put('emis:token:gtk', ['c' => 'cookie'], now()->addHour());
        Cache::put('emis:token:siswa', 'tok', now()->addHour());
        EmisGtkStaging::create(['emis_gtk_id' => 'p-1', 'full_name_with_titles' => 'P', 'status' => 'pending', 'source' => 'x', 'raw_data' => []]);
        EmisStaging::create(['source' => 'bulk', 'nisn' => '9000000001', 'nik' => null, 'name' => 'S', 'status' => 'pending', 'raw_data' => []]);

        // token sync:trigger tanpa staging:write -> clear_staging ditolak (sampai ke cek otorisasi)
        $trig = $this->h('emis_gtk', ['sync:trigger']);
        $this->postJson('/api/emis-gtk/pull', ['clear_staging' => true], $trig)->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN_TOKEN_ABILITY');
        $this->assertSame(1, EmisGtkStaging::count());
        $trigS = $this->h('emis_siswa', ['sync:trigger']);
        $this->postJson('/api/emis/pull', ['clear_staging' => true], $trigS)->assertStatus(403);
        $this->assertSame(1, EmisStaging::count());

        // token read-only tidak bisa import/pull
        $ro = $this->h('emis_gtk', null);
        $this->imp($ro, ['a'], 1, 1)->assertStatus(403);
        $this->postJson('/api/emis-gtk/pull', ['clear_staging' => true], $ro)->assertStatus(403);

        // commit siswa & GTK ditolak untuk token (walau ability penuh)
        $full = ['staging:read', 'staging:write', 'reconcile:run', 'sync:trigger', 'sync:cancel', 'tasks:read', 'credentials:update'];
        $this->postJson('/api/emis/commit', [], $this->h('emis_siswa', $full))->assertStatus(403)->assertJsonPath('error.code', 'INTEGRATION_TOKEN_COMMIT_FORBIDDEN');
        $this->postJson('/api/emis-gtk/commit', [], $this->h('emis_gtk', $full))->assertStatus(403)->assertJsonPath('error.code', 'INTEGRATION_TOKEN_COMMIT_FORBIDDEN');

        // ubah status update task ditolak untuk token
        $task = EmisUpdateTask::create(['entity_type' => 'student', 'entity_id' => (string) Str::uuid(), 'source_type' => 'student_master_update', 'changed_fields' => [], 'status' => 'pending']);
        $this->patchJson('/api/emis/update-tasks/'.$task->id, ['status' => 'completed'], $this->h('emis_siswa', $full))->assertStatus(403);
        $this->assertSame('pending', $task->fresh()->status);

        // lintas domain
        $this->postJson('/api/emis-gtk/pull', [], $this->h('emis_siswa', $full))->assertStatus(401);
        $this->postJson('/api/emis/pull', [], $this->h('emis_gtk', $full))->assertStatus(401);
        $this->imp($this->h('emis_siswa', $full), ['a'], 1, 1)->assertStatus(401);

        // sesi tanpa permission
        $guru = User::factory()->create(['role' => 'guru']);
        $this->actingAs($guru)->postJson('/api/emis-gtk/pull', ['clear_staging' => true])->assertStatus(403);
        $this->actingAs($guru)->postJson('/api/emis/commit', [])->assertStatus(403);
        $this->assertSame(1, EmisGtkStaging::count());
    }
}
