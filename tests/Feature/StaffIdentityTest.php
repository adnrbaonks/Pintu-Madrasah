<?php

namespace Tests\Feature;

use App\Models\Rombel;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StaffIdentityTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_staff_name_accessor_returns_full_name_with_titles(): void
    {
        $staff = Staff::factory()->create([
            'full_name_with_titles' => 'AAN KUSNANDI, S.Pd.',
        ]);

        $this->assertSame('AAN KUSNANDI, S.Pd.', $staff->name);
    }

    public function test_users_page_lists_staff_options_with_full_name(): void
    {
        $staff = Staff::factory()->create([
            'full_name_with_titles' => 'AAN KUSNANDI, S.Pd.',
            'nip' => '198203072009011008',
        ]);
        User::factory()->create(['staff_id' => $staff->id]);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get('/users')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Users/Index', false)
                ->where('staffOptions.0.label', 'AAN KUSNANDI, S.Pd. (198203072009011008)'));
    }

    public function test_class_print_page_shows_homeroom_teacher_full_name(): void
    {
        $staff = Staff::factory()->create([
            'full_name_with_titles' => 'SITI AMINAH, S.Pd.',
        ]);
        $rombel = Rombel::factory()->create([
            'homeroom_teacher_id' => $staff->id,
        ]);
        $user = User::factory()->create(['role' => 'staf']);

        $this->actingAs($user)
            ->get(route('print.daftar-kelas', ['rombel_id' => $rombel->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Print/DaftarKelas', false)
                ->where('rombel.homeroom_teacher', 'SITI AMINAH, S.Pd.'));
    }
}
