<?php

namespace Tests\Feature;

use App\Models\EmisGtkStaging;
use App\Models\EmisImportBatch;
use App\Models\EmisReconciliationItem;
use App\Models\Staff;
use App\Models\User;
use App\Services\EmisReconciliationService;
use App\Services\IntegrationTokenService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class EmisTokenScopeAndMatchingTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function headers(string $purpose, ?array $abilities = null): array
    {
        $user = User::factory()->create(['role' => 'operator']);
        $t = app(IntegrationTokenService::class)->issue($user, $purpose, 'T', null, $abilities);

        return ['X-Integration-Token' => $t['plain_token']];
    }

    public function test_legacy_and_read_only_tokens_cannot_run_write_operations(): void
    {
        $h = $this->headers('emis_siswa');
        $this->getJson('/api/emis/staging', $h)->assertOk();
        foreach (['/api/emis/reconcile', '/api/emis/pull', '/api/emis/cancel-sync', '/api/emis/token'] as $url) {
            $this->postJson($url, [], $h)->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN_TOKEN_ABILITY');
        }
        $g = $this->headers('emis_gtk');
        $this->getJson('/api/emis-gtk/staging', $g)->assertOk();
        $this->postJson('/api/emis-gtk/import', ['data' => [['name' => 'A']]], $g)->assertStatus(403);
        $this->postJson('/api/emis-gtk/reconcile', [], $g)->assertStatus(403);
    }

    public function test_token_with_specific_ability_is_allowed_only_for_that_ability(): void
    {
        $h = $this->headers('emis_siswa', ['reconcile:run']);
        $this->postJson('/api/emis/reconcile', [], $h)->assertOk();
        $this->postJson('/api/emis/cancel-sync', [], $h)->assertStatus(403);
        $this->getJson('/api/emis/staging', $h)->assertStatus(403);
    }

    public function test_invalid_ability_cannot_be_issued_and_token_cannot_commit(): void
    {
        $user = User::factory()->create(['role' => 'operator']);
        $this->expectException(\InvalidArgumentException::class);
        app(IntegrationTokenService::class)->issue($user, 'emis_siswa', 'x', null, ['everything']);
    }

    public function test_status_endpoints_are_split_per_domain(): void
    {
        $s = $this->getJson('/api/emis/status', $this->headers('emis_siswa'))->assertOk();
        $s->assertJsonMissingPath('emis_gtk');
        $g = $this->getJson('/api/emis-gtk/status', $this->headers('emis_gtk'))->assertOk();
        $g->assertJsonMissingPath('emis_siswa')->assertJsonStructure(['emis_gtk' => ['active']]);
        $this->getJson('/api/emis-gtk/status', $this->headers('emis_siswa'))->assertStatus(401);
    }

    public function test_gtk_import_is_session_scoped_and_does_not_truncate_before_final_page(): void
    {
        $h = $this->headers('emis_gtk', ['staging:write', 'staging:read']);
        EmisGtkStaging::create(['emis_gtk_id' => 'old-1', 'full_name_with_titles' => 'LAMA', 'status' => 'pending', 'source' => 'x', 'raw_data' => []]);

        $this->postJson('/api/emis-gtk/import', ['data' => [['name' => 'Baru Satu', 'uuid' => 'n-1']], 'page' => 1, 'total_pages' => 2], $h)->assertOk();
        $this->assertDatabaseHas('emis_gtk_stagings', ['emis_gtk_id' => 'old-1']);

        $this->postJson('/api/emis-gtk/import', ['data' => [['name' => 'Baru Dua', 'uuid' => 'n-2']], 'page' => 2, 'total_pages' => 2], $h)->assertOk();
        $this->assertDatabaseMissing('emis_gtk_stagings', ['emis_gtk_id' => 'old-1']);
        $this->assertSame(2, EmisGtkStaging::count());
        $this->assertSame(1, EmisGtkStaging::distinct()->count('import_session_id'));

        // import berulang idempotent (identity sama)
        $this->postJson('/api/emis-gtk/import', ['data' => [['name' => 'Baru Satu', 'uuid' => 'n-1'], ['name' => 'Baru Dua', 'uuid' => 'n-2']], 'page' => 1, 'total_pages' => 1], $h)->assertOk();
        $this->assertSame(2, EmisGtkStaging::count());
        $this->assertDatabaseHas('integration_logs', ['event' => 'staging.cleared']);
    }

    public function test_gtk_import_failure_midway_keeps_previous_staging(): void
    {
        $h = $this->headers('emis_gtk', ['staging:write']);
        EmisGtkStaging::create(['emis_gtk_id' => 'old-1', 'full_name_with_titles' => 'LAMA', 'status' => 'pending', 'source' => 'x', 'raw_data' => []]);
        $this->postJson('/api/emis-gtk/import', ['data' => [['nama' => 'tanpa name']]], $h)->assertStatus(422);
        $this->assertDatabaseHas('emis_gtk_stagings', ['emis_gtk_id' => 'old-1']);
    }

    public function test_pull_clear_staging_requires_write_ability_and_keeps_committed(): void
    {
        $ro = $this->headers('emis_gtk', ['sync:trigger']);
        EmisGtkStaging::create(['emis_gtk_id' => 'c-1', 'full_name_with_titles' => 'C', 'status' => 'committed', 'source' => 'x', 'raw_data' => []]);
        EmisGtkStaging::create(['emis_gtk_id' => 'p-1', 'full_name_with_titles' => 'P', 'status' => 'pending', 'source' => 'x', 'raw_data' => []]);
        // tanpa token EMIS aktif pull ditolak 422 sebelum menghapus
        $this->postJson('/api/emis-gtk/pull', ['clear_staging' => true], $ro)->assertStatus(422);
        $this->assertSame(2, EmisGtkStaging::count());
    }

    public function test_gtk_matching_by_each_identifier_conflict_and_duplicates(): void
    {
        $svc = app(EmisReconciliationService::class);
        $mk = fn (array $a) => Staff::factory()->create($a);
        $a = $mk(['emis_gtk_id' => 'g-a', 'nik' => '3201000000000001', 'nuptk' => '1111', 'nip' => '2222', 'npk' => '3333']);
        $b = $mk(['emis_gtk_id' => 'g-b', 'nik' => '3201000000000002']);

        $st = fn (array $a) => EmisGtkStaging::create($a + ['full_name_with_titles' => 'X', 'status' => 'pending', 'source' => 'x', 'raw_data' => []]);
        $st(['emis_gtk_id' => 'g-a']);
        $st(['nik' => '3201000000000001', 'emis_gtk_id' => null]);
        $st(['nuptk' => '1111', 'emis_gtk_id' => null]);
        $st(['nip' => '2222', 'emis_gtk_id' => null]);
        $st(['npk' => '3333', 'emis_gtk_id' => null]);
        $st(['emis_gtk_id' => 'g-a', 'nik' => '3201000000000001', 'nuptk' => '1111']);
        $st(['emis_gtk_id' => 'g-a', 'nik' => '3201000000000002']); // konflik a vs b
        $st(['emis_gtk_id' => 'zzz']); // tidak ditemukan
        $st(['emis_gtk_id' => null]); // tanpa identitas

        $batch = $svc->reconcileStaff();
        $items = EmisReconciliationItem::where('batch_id', $batch->id)->get();
        $matched = $items->whereNotNull('staging_id')->whereNotNull('entity_id')->where('classification', '!=', 'needs_review');
        $this->assertCount(6, $matched);
        $this->assertTrue($matched->every(fn ($i) => $i->entity_id === $a->id));
        $this->assertSame(2, $batch->needs_review_count);
        $this->assertSame(1, $batch->emis_only_count);
        $this->assertEmpty($items->where('entity_id', $b->id)->whereNotNull('staging_id')->where('classification', '!=', 'needs_review')->all());

        // duplikat identifier di master
        $mk(['npk' => '9999']);
        $mk(['npk' => '9999']);
        EmisGtkStaging::query()->delete();
        $st(['npk' => '9999', 'emis_gtk_id' => null]);
        $batch2 = $svc->reconcileStaff();
        $this->assertSame(1, $batch2->needs_review_count);
        $item = EmisReconciliationItem::where('batch_id', $batch2->id)->whereNotNull('staging_id')->first();
        $this->assertNull($item->entity_id);
        $this->assertStringContainsString('Duplikat', $item->review_notes);
        $this->assertInstanceOf(EmisImportBatch::class, $batch2);
        Cache::flush();
        $this->assertTrue(Str::isUuid($item->id));
    }
}
