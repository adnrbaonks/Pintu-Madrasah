<?php

namespace Tests\Feature;

use App\Jobs\CommitEmisGtkStagingJob;
use App\Models\EmisGtkStaging;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EmisGtkStagingTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_emis_gtk_page_lists_staging_ordered_by_full_name(): void
    {
        $user = User::factory()->create(['role' => 'staf']);

        EmisGtkStaging::factory()->create(['full_name_with_titles' => 'ZAKI']);
        EmisGtkStaging::factory()->create(['full_name_with_titles' => 'AHMAD']);

        $this->actingAs($user)
            ->get('/emis/gtk')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Emis/Gtk', false)
                ->where('stagings.data.0.full_name_with_titles', 'AHMAD')
                ->where('summary.total', 2)
                ->has('stagings.data.0.name'));
    }

    public function test_bookmarklet_import_maps_columns_without_duplicate_name_column(): void
    {
        $response = $this->postJson('/api/emis-gtk/import', [
            'page' => 1,
            'total_pages' => 1,
            'total_count' => 1,
            'data' => [[
                'name' => 'aan kusnandi',
                'uuid' => 'gtk-uuid-1',
                'nik' => '3211250703820002',
                'nip' => '198203072009011008',
                'npk' => '5820970023087',
                'nrg' => '1234567890',
                'peg_id' => '91000082100218',
                'fungsi' => 'Guru',
                'employment' => 'pns',
                'active' => true,
            ]],
        ]);

        $response->assertOk()->assertJsonPath('saved', 1);

        $staging = EmisGtkStaging::where('emis_gtk_id', 'gtk-uuid-1')->firstOrFail();

        $this->assertSame('AAN KUSNANDI', $staging->full_name_with_titles);
        $this->assertSame('AAN KUSNANDI', $staging->name);
        $this->assertSame('Guru', $staging->function_role);
        $this->assertSame('1234567890', $staging->nrg);
        $this->assertSame('91000082100218', $staging->peg_id);
        $this->assertTrue($staging->k_aktif);
        $this->assertSame('bookmarklet', $staging->source);
        $this->assertNotNull($staging->last_seen_at);
        $this->assertFalse(Schema::hasColumn('emis_gtk_stagings', 'name'));
    }

    public function test_commit_marks_staging_without_identity_as_failed(): void
    {
        $staging = EmisGtkStaging::factory()->withoutIdentity()->create();

        (new CommitEmisGtkStagingJob)->handle();

        $staging->refresh();

        $this->assertSame('failed', $staging->status);
        $this->assertStringContainsString('Identitas', $staging->error_message);
        $this->assertSame(0, Staff::withTrashed()->count());
    }

    public function test_commit_creates_staff_from_staging_without_fake_defaults(): void
    {
        $staging = EmisGtkStaging::factory()->create([
            'nik' => '3211250703820002',
            'full_name_with_titles' => 'AAN KUSNANDI',
        ]);

        (new CommitEmisGtkStagingJob)->handle();

        $staging->refresh();
        $staff = Staff::where('nik', '3211250703820002')->firstOrFail();

        $this->assertSame('committed', $staging->status);
        $this->assertNull($staging->error_message);
        $this->assertSame('AAN KUSNANDI', $staff->full_name_with_titles);
        $this->assertSame($staging->gender, $staff->gender);
        $this->assertSame($staging->birth_place, $staff->birth_place);
        $this->assertSame($staging->birth_date->toDateString(), $staff->birth_date->toDateString());
    }

    public function test_commit_matches_existing_staff_by_nuptk_only(): void
    {
        $staff = Staff::factory()->create([
            'nik' => null,
            'nuptk' => '1234567890123456',
            'full_name_with_titles' => 'NAMA LAMA',
        ]);

        $staging = EmisGtkStaging::factory()->nuptkOnly()->create([
            'nuptk' => '1234567890123456',
            'full_name_with_titles' => 'NAMA BARU',
        ]);

        (new CommitEmisGtkStagingJob)->handle();

        $staging->refresh();
        $staff->refresh();

        $this->assertSame('committed', $staging->status);
        $this->assertSame(1, Staff::withTrashed()->count());
        $this->assertSame('NAMA BARU', $staff->full_name_with_titles);
    }

    public function test_commit_does_not_create_staff_when_required_fields_are_missing(): void
    {
        $staging = EmisGtkStaging::factory()->incomplete()->create([
            'nik' => '3211250703820003',
        ]);

        (new CommitEmisGtkStagingJob)->handle();

        $staging->refresh();

        $this->assertSame('failed', $staging->status);
        $this->assertStringContainsString('Data wajib belum lengkap', $staging->error_message);
        $this->assertSame(0, Staff::withTrashed()->count());
    }

    public function test_commit_does_not_blank_existing_staff_fields_with_empty_staging_values(): void
    {
        $staff = Staff::factory()->create([
            'nik' => '3211250703820004',
            'phone' => '08123456789',
            'gender' => 'P',
            'birth_place' => 'Bandung',
            'birth_date' => '1990-01-01',
            'employment_status' => 'pns',
        ]);

        EmisGtkStaging::factory()->incomplete()->create([
            'nik' => '3211250703820004',
            'phone' => null,
        ]);

        (new CommitEmisGtkStagingJob)->handle();

        $staff->refresh();

        $this->assertSame('P', $staff->gender);
        $this->assertSame('Bandung', $staff->birth_place);
        $this->assertSame('08123456789', $staff->phone);
        $this->assertSame('pns', $staff->employment_status);
    }
}
