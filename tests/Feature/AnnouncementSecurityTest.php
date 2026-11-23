<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AnnouncementSecurityTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_announcements_are_filtered_by_target_scope_and_target_roles_in_backend(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $kaurTu = User::factory()->create(['role' => 'kaur_tu']);
        $guru = User::factory()->create(['role' => 'guru']);
        $pegawai = User::factory()->create(['role' => 'pegawai']);

        // 1. Pengumuman umum (scope: all)
        $publicAnn = Announcement::create([
            'title' => 'Pengumuman Umum untuk Seluruh Madrasah',
            'content' => 'Konten pengumuman umum.',
            'target_scope' => 'all',
            'priority' => 'normal',
            'publish_date' => now()->subDays(4)->toDateString(),
            'is_active' => true,
            'author_user_id' => $kaurTu->id,
        ]);

        // 2. Pengumuman khusus GTK (scope: gtk_only)
        $gtkAnn = Announcement::create([
            'title' => 'Rapat Pleno Dewan Guru & Staf',
            'content' => 'Khusus GTK madrasah.',
            'target_scope' => 'gtk_only',
            'priority' => 'high',
            'publish_date' => now()->subDays(3)->toDateString(),
            'is_active' => true,
            'author_user_id' => $kaurTu->id,
        ]);

        // 3. Pengumuman khusus peran guru (scope: role_specific, target_roles: ['guru'])
        $guruAnn = Announcement::create([
            'title' => 'Instruksi Pengisian Nilai Rapor Kemenag',
            'content' => 'Khusus role guru.',
            'target_scope' => 'role_specific',
            'target_roles' => ['guru'],
            'priority' => 'high',
            'publish_date' => now()->subDays(2)->toDateString(),
            'is_active' => true,
            'author_user_id' => $kaurTu->id,
        ]);

        // 4. Pengumuman khusus peran pegawai non-guru dibuat oleh Pegawai
        $pegawaiAnn = Announcement::create([
            'title' => 'Pembersihan Fasilitas Kantor',
            'content' => 'Khusus tenaga kependidikan pegawai.',
            'target_scope' => 'role_specific',
            'target_roles' => ['pegawai'],
            'priority' => 'normal',
            'publish_date' => now()->subDay()->toDateString(),
            'is_active' => true,
            'author_user_id' => $pegawai->id,
        ]);

        // Verifikasi Guru:
        // Guru melihat: publicAnn (all), gtkAnn (gtk_only), guruAnn (role_specific guru).
        // Guru TIDAK boleh melihat: pegawaiAnn (role_specific pegawai).
        $this->actingAs($guru)
            ->get('/pengumuman')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Announcements/Index', false)
                ->has('announcements', 3)
                ->where('announcements.0.id', $guruAnn->id)
                ->where('announcements.1.id', $gtkAnn->id)
                ->where('announcements.2.id', $publicAnn->id)
            );

        // Verifikasi Pegawai:
        // Pegawai melihat: publicAnn (all), gtkAnn (gtk_only), pegawaiAnn (karena author dan role matches).
        // Pegawai TIDAK boleh melihat: guruAnn (role_specific guru).
        $this->actingAs($pegawai)
            ->get('/pengumuman')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Announcements/Index', false)
                ->has('announcements', 3)
                ->where('announcements.0.id', $pegawaiAnn->id)
                ->where('announcements.1.id', $gtkAnn->id)
                ->where('announcements.2.id', $publicAnn->id)
            );

        // Verifikasi Kaur TU (author dari publicAnn, gtkAnn, guruAnn):
        // Kaur TU melihat seluruh pengumuman yang dibuatnya + pengumuman scope all & gtk.
        // PegawaiAnn bukan buatan kaurTu dan targetnya hanya ['pegawai'], sehingga kaurTu tidak melihat pegawaiAnn.
        $this->actingAs($kaurTu)
            ->get('/pengumuman')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Announcements/Index', false)
                ->has('announcements', 3)
            );

        // Verifikasi Admin Sistem:
        // Admin melihat SELURUH pengumuman (4 buah) tanpa filter scope/roles.
        $this->actingAs($admin)
            ->get('/pengumuman')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Announcements/Index', false)
                ->has('announcements', 4)
            );
    }
}
