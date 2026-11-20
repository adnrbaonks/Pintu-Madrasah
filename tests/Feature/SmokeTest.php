<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class SmokeTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_login_page_can_be_rendered(): void
    {
        $this->get('/login')->assertOk();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_user_can_login_with_username_and_password(): void
    {
        $user = User::factory()->create([
            'username' => 'admin-smoke',
            'role' => 'admin',
        ]);

        $response = $this->post('/login', [
            'username' => 'admin-smoke',
            'password' => 'password',
        ]);

        $response->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    }

    public function test_inactive_user_cannot_login(): void
    {
        User::factory()->inactive()->create([
            'username' => 'nonaktif-smoke',
        ]);

        $response = $this->post('/login', [
            'username' => 'nonaktif-smoke',
            'password' => 'password',
        ]);

        $response->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    public function test_authenticated_user_can_open_dashboard(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/')
            ->assertOk();
    }

    public function test_staff_can_open_students_index(): void
    {
        $user = User::factory()->create(['role' => 'staf']);

        $this->actingAs($user)
            ->get('/students')
            ->assertOk();
    }

    public function test_guru_cannot_open_students_index(): void
    {
        $user = User::factory()->guru()->create();

        $this->actingAs($user)
            ->get('/students')
            ->assertForbidden();
    }
}
