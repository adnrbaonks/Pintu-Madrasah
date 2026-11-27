<?php

namespace Tests\Feature;

use App\Models\EmisGtkStaging;
use App\Models\EmisImportBatch;
use App\Models\EmisStaging;
use App\Models\Staff;
use App\Models\Student;
use App\Models\User;
use App\Services\IntegrationTokenService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class EmisApiSecurityHardeningTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function tokenHeaders(User $user, string $purpose): array
    {
        $token = app(IntegrationTokenService::class)->issue($user, $purpose, 'Token '.$purpose);

        return ['X-Integration-Token' => $token['plain_token']];
    }

    public function test_post_reconcile_student_with_integration_token_does_not_leak_pii_or_items(): void
    {
        $operator = User::factory()->create(['role' => 'operator']);
        $headers = $this->tokenHeaders($operator, 'emis_siswa');

        Student::factory()->create([
            'nisn' => '5000000001', 'nik' => '3201777766665555', 'kk_number' => '3201444433332222',
            'name' => 'Nama Bocor Siswa', 'birth_date' => '2011-05-06',
            'father_name' => 'Ayah Bocor', 'mother_name' => 'Ibu Bocor', 'guardian_nik' => '3201123412341234',
        ]);
        EmisStaging::create([
            'source' => 'bulk', 'nisn' => '5000000001', 'nik' => '3201777766665555',
            'name' => 'Nama Bocor Siswa EMIS', 'status' => 'pending', 'raw_data' => [],
        ]);

        $response = $this->postJson('/api/emis/reconcile', [], $headers)->assertOk();
        $body = $response->getContent();

        foreach (['5000000001', '3201777766665555', '3201444433332222', 'Nama Bocor', '2011-05-06', 'Ayah Bocor', 'Ibu Bocor', '3201123412341234'] as $leak) {
            $this->assertStringNotContainsString($leak, $body);
        }
        $this->assertNull($response->json('data.items'));
        $this->assertNull($response->json('batch.items'));
        $this->assertSame(1, $response->json('data.items_count'));
        $this->assertSame(1, $response->json('data.difference_count'));
        $this->assertNotEmpty($response->json('data.id'));
    }

    public function test_post_reconcile_gtk_with_integration_token_does_not_leak_pii_or_items(): void
    {
        $operator = User::factory()->create(['role' => 'operator']);
        $headers = $this->tokenHeaders($operator, 'emis_gtk');

        Staff::factory()->create([
            'nik' => '3201999988887777', 'nip' => '198001012005011001', 'nuptk' => '1234567890123456',
            'full_name_with_titles' => 'Nama Bocor GTK', 'phone' => '081234567890', 'email' => 'bocor@example.test',
        ]);
        EmisGtkStaging::factory()->create([
            'nik' => '3201999988887777', 'nip' => '198001012005011001',
            'full_name_with_titles' => 'NAMA BOCOR GTK EMIS', 'status' => 'pending',
        ]);

        $response = $this->postJson('/api/emis-gtk/reconcile', [], $headers)->assertOk();
        $body = $response->getContent();

        foreach (['3201999988887777', '198001012005011001', '1234567890123456', 'Nama Bocor', 'NAMA BOCOR', '081234567890', 'bocor@example.test'] as $leak) {
            $this->assertStringNotContainsString($leak, $body);
        }
        $this->assertNull($response->json('data.items'));
        $this->assertSame(1, $response->json('data.items_count'));
    }

    public function test_student_token_cannot_reconcile_gtk_and_gtk_token_cannot_reconcile_students(): void
    {
        $operator = User::factory()->create(['role' => 'operator']);

        $this->postJson('/api/emis-gtk/reconcile', [], $this->tokenHeaders($operator, 'emis_siswa'))->assertStatus(401);
        $this->postJson('/api/emis/reconcile', [], $this->tokenHeaders($operator, 'emis_gtk'))->assertStatus(401);
    }

    public function test_student_staging_is_paginated_with_bulk_lookup_and_no_n_plus_one(): void
    {
        $operator = User::factory()->create(['role' => 'operator']);

        $this->actingAs($operator)->getJson('/api/emis/staging')
            ->assertOk()->assertJsonPath('meta.total', 0)->assertJsonPath('data', []);

        for ($i = 1; $i <= 30; $i++) {
            EmisStaging::create([
                'source' => 'bulk', 'nisn' => sprintf('60000%05d', $i), 'nik' => sprintf('32016000%08d', $i),
                'name' => "Siswa Staging {$i}", 'status' => 'pending', 'raw_data' => [],
            ]);
        }
        Student::factory()->create(['nisn' => '6000000003']);

        DB::enableQueryLog();
        $page1 = $this->actingAs($operator)->getJson('/api/emis/staging?per_page=10&page=1')->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(10, $page1->json('data'));
        $this->assertSame(30, $page1->json('meta.total'));
        $this->assertSame(3, $page1->json('meta.last_page'));
        $this->assertLessThan(15, $queries, 'Query staging tidak boleh tumbuh per baris (N+1).');
        $all = $this->actingAs($operator)->getJson('/api/emis/staging?per_page=200')->assertOk()->json('data');
        $this->assertSame('update', collect($all)->firstWhere('nisn', '6000000003')['match_status']);
        $this->assertSame(1, collect($all)->where('match_status', 'update')->count());

        $page3 = $this->actingAs($operator)->getJson('/api/emis/staging?per_page=10&page=3')->assertOk();
        $this->assertCount(10, $page3->json('data'));

        $this->actingAs($operator)->getJson('/api/emis/staging?per_page=100000')->assertOk()
            ->assertJsonPath('meta.per_page', 200);
    }

    public function test_gtk_staging_is_paginated_sanitized_and_domain_isolated(): void
    {
        $operator = User::factory()->create(['role' => 'operator']);
        $headers = $this->tokenHeaders($operator, 'emis_gtk');

        EmisGtkStaging::factory()->count(12)->create(['status' => 'pending']);
        Staff::factory()->create(['nik' => EmisGtkStaging::first()->nik]);

        DB::enableQueryLog();
        $res = $this->getJson('/api/emis-gtk/staging?per_page=5', $headers)->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(5, $res->json('data'));
        $this->assertSame(12, $res->json('meta.total'));
        $this->assertLessThan(12, $queries);
        $this->assertStringNotContainsString(EmisGtkStaging::first()->nik, $res->getContent());

        // Token GTK tidak boleh membaca staging siswa
        $this->getJson('/api/emis/staging', $headers)->assertStatus(401);
    }

    public function test_least_privilege_permissions_for_session_users(): void
    {
        $staf = User::factory()->create(['role' => 'staf']);
        $operator = User::factory()->create(['role' => 'operator']);
        $kaur = User::factory()->create(['role' => 'kaur_tu']);
        $kamad = User::factory()->create(['role' => 'kepala_madrasah']);
        $admin = User::factory()->create(['role' => 'admin']);

        // Lihat staging: staf, operator, kaur_tu, kepala, admin (view_emis)
        foreach ([$staf, $operator, $kaur, $kamad, $admin] as $u) {
            $this->actingAs($u)->getJson('/api/emis/staging')->assertOk();
        }

        // Rekonsiliasi hanya reconcile_emis
        $this->actingAs($staf)->postJson('/api/emis/reconcile')->assertStatus(403);
        $this->actingAs($kaur)->postJson('/api/emis/reconcile')->assertStatus(403);
        $this->actingAs($kamad)->postJson('/api/emis/reconcile')->assertStatus(403);
        $this->actingAs($operator)->postJson('/api/emis/reconcile')->assertOk();

        // Pull / cancel / commit memerlukan sync_emis
        foreach ([$staf, $kaur, $kamad] as $u) {
            $this->actingAs($u)->postJson('/api/emis/pull')->assertStatus(403);
            $this->actingAs($u)->postJson('/api/emis/cancel-sync')->assertStatus(403);
            $this->actingAs($u)->postJson('/api/emis/commit')->assertStatus(403);
            $this->actingAs($u)->postJson('/api/emis-gtk/pull')->assertStatus(403);
        }

        // Pengguna tanpa permission EMIS ditolak seluruhnya
        $guru = User::factory()->create(['role' => 'guru']);
        $this->actingAs($guru)->getJson('/api/emis/staging')->assertStatus(403);
        $this->actingAs($guru)->postJson('/api/emis/reconcile')->assertStatus(403);
    }

    public function test_reconcile_results_are_identical_across_chunk_boundaries_and_idempotent(): void
    {
        $operator = User::factory()->create(['role' => 'operator']);
        $this->assertSame(0, EmisImportBatch::count());

        // 520 baris staging melewati batas chunk 500; 1 master pintu-only, 1 konflik identitas
        $rows = [];
        for ($i = 1; $i <= 520; $i++) {
            $rows[] = [
                'id' => (string) Str::uuid(), 'source' => 'bulk', 'nisn' => sprintf('70%08d', $i), 'nik' => $i === 100 ? '3201700000000101' : sprintf('32017%011d', $i),
                'name' => "Staging {$i}", 'status' => 'pending', 'raw_data' => '[]',
                'created_at' => now(), 'updated_at' => now(),
            ];
        }
        DB::table('emis_staging')->insert($rows);

        Student::factory()->create(['nisn' => '7000000010', 'nik' => '3201700000000010', 'name' => 'Staging 10']);
        Student::factory()->create(['nisn' => '7000000515', 'nik' => '3201700000000515', 'name' => 'Staging 515']);
        Student::factory()->create(['nisn' => '7999999999', 'nik' => '3201799999999999']);
        // Konflik: NISN milik siswa A, NIK milik siswa B
        Student::factory()->create(['nisn' => '7000000100', 'nik' => '3201700000009999']);
        Student::factory()->create(['nisn' => '7000009998', 'nik' => '3201700000000101']);

        $first = $this->actingAs($operator)->postJson('/api/emis/reconcile')->assertOk()->json('data');
        $second = $this->actingAs($operator)->postJson('/api/emis/reconcile')->assertOk()->json('data');

        foreach (['total_rows', 'matched_identical_count', 'difference_count', 'pintu_only_count', 'emis_only_count', 'needs_review_count', 'items_count'] as $field) {
            $this->assertSame($first[$field], $second[$field], "Hasil tidak idempotent: {$field}");
        }
        $this->assertSame(520, $first['total_rows']);
        $this->assertSame(2, $first['matched_identical_count']);
        $this->assertSame(1, $first['needs_review_count']);
        $this->assertSame(1, $first['difference_count']);
        $this->assertSame(1, $first['pintu_only_count']);
        $this->assertSame(516, $first['emis_only_count']);
        $this->assertSame(521, $first['items_count']);
    }
}
