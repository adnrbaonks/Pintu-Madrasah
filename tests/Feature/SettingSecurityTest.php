<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class SettingSecurityTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_setting_secrets_are_stored_encrypted_and_transparently_decrypted(): void
    {
        Setting::flushCache();
        Setting::set('gemini_api_key', 'raw-gemini-secret-12345');

        $rawRecord = Setting::where('key', 'gemini_api_key')->firstOrFail();
        $this->assertNotSame('raw-gemini-secret-12345', $rawRecord->value);
        $this->assertSame('raw-gemini-secret-12345', decrypt($rawRecord->value));

        Setting::flushCache();
        $this->assertSame('raw-gemini-secret-12345', Setting::get('gemini_api_key'));
    }

    public function test_settings_page_never_exposes_raw_secrets_in_inertia_props(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Setting::set('gemini_api_key', 'ai-key-secret-999');
        Setting::set('api_key', 'gateway-secret-888');

        $response = $this->actingAs($admin)->get('/pengaturan');
        $response->assertOk();

        $page = $response->viewData('page');
        $this->assertArrayNotHasKey('gemini_api_key', $page['props']['settings']);
        $this->assertArrayNotHasKey('api_key', $page['props']['settings']);
        $this->assertTrue($page['props']['settings']['has_gemini_api_key']);
        $this->assertTrue($page['props']['settings']['has_api_key']);
    }

    public function test_check_api_key_rejects_query_parameter_and_restricts_legacy_key(): void
    {
        Setting::set('api_key', 'legacy-fixed-key-000');

        $this->getJson('/api/v1/classes?api_key=legacy-fixed-key-000')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'MISSING_API_KEY');

        $this->withHeader('X-API-KEY', 'legacy-fixed-key-000')
            ->getJson('/api/v1/classes')
            ->assertOk();

        // Legacy key tidak boleh otomatis memiliki scope PII
        $this->withHeader('X-API-KEY', 'legacy-fixed-key-000')
            ->getJson('/api/v1/students?fields=id,nik,address')
            ->assertOk()
            ->assertJsonMissing(['address' => 'Alamat privat']);
    }
}
