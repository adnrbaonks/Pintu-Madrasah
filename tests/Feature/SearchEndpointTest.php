<?php

namespace Tests\Feature;

use App\Models\Staff;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class SearchEndpointTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guest_cannot_access_search(): void
    {
        $this->getJson('/api/search?q=abc')->assertUnauthorized();
    }

    public function test_authenticated_user_gets_matching_students(): void
    {
        $user = User::factory()->create();
        Student::factory()->create(['name' => 'Ahmad Fauzi']);

        $this->actingAs($user)
            ->getJson('/api/search?q=Ahmad')
            ->assertOk()
            ->assertJsonPath('results.0.type', 'siswa')
            ->assertJsonPath('results.0.label', 'Ahmad Fauzi');
    }

    public function test_gtk_result_links_to_gtk_search_page(): void
    {
        $user = User::factory()->create();
        Staff::factory()->create([
            'full_name_with_titles' => 'AAN KUSNANDI',
            'nip' => '198203072009011008',
        ]);

        $this->actingAs($user)
            ->getJson('/api/search?q=198203072009011008')
            ->assertOk()
            ->assertJsonFragment(['href' => '/gtk?search=198203072009011008']);
    }

    public function test_search_route_uses_web_middleware(): void
    {
        $route = collect(app('router')->getRoutes()->getRoutes())
            ->first(fn ($route) => $route->uri() === 'api/search');

        $this->assertNotNull($route);
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());
    }
}
