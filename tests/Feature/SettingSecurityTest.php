<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_legacy_plaintext_secrets_are_migrated_and_encrypted_transparently(): void
    {
        Setting::flushCache();

        // 1. Simpan raw plaintext langsung ke database (meniru data legacy lama)
        DB::table('settings')->insert([
            'key' => 'telegram_bot_token',
            'value' => 'raw-plaintext-telegram-token-987654',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $rawBefore = DB::table('settings')->where('key', 'telegram_bot_token')->value('value');
        $this->assertSame('raw-plaintext-telegram-token-987654', $rawBefore);

        // 2. Akses melalui Setting::get() harus otomatis mengembalikan nilai asli DAN mengupdate database menjadi terenkripsi (on-read transparent migration)
        $val = Setting::get('telegram_bot_token');
        $this->assertSame('raw-plaintext-telegram-token-987654', $val);

        $rawAfter = DB::table('settings')->where('key', 'telegram_bot_token')->value('value');
        $this->assertNotSame('raw-plaintext-telegram-token-987654', $rawAfter);
        $this->assertSame('raw-plaintext-telegram-token-987654', decrypt($rawAfter));

        // 3. Uji Artisan command migrasi batch
        Setting::flushCache();
        DB::table('settings')->insert([
            'key' => 'rdm_api_key',
            'value' => 'legacy-rdm-secret-key-112233',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('settings:encrypt-secrets')
            ->expectsOutputToContain('Migrasi selesai. 1 secret terenkripsi')
            ->assertSuccessful();

        $rawRdm = DB::table('settings')->where('key', 'rdm_api_key')->value('value');
        $this->assertNotSame('legacy-rdm-secret-key-112233', $rawRdm);
        $this->assertSame('legacy-rdm-secret-key-112233', decrypt($rawRdm));
        $this->assertSame('legacy-rdm-secret-key-112233', Setting::get('rdm_api_key'));
    }

    public function test_ciphertext_from_another_app_key_is_never_double_wrapped_and_migration_is_idempotent(): void
    {
        Setting::flushCache();

        $oldEncrypter = new Encrypter(random_bytes(32), config('app.cipher'));
        $oldCiphertext = $oldEncrypter->encrypt('rotated-secret-value');

        DB::table('settings')->insert([
            [
                'key' => 'gemini_api_key',
                'value' => $oldCiphertext,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'emis_sso_token',
                'value' => 'plaintext-emis-token',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->assertSame('invalid_ciphertext', Setting::encryptionState($oldCiphertext));
        $this->assertTrue(Setting::isEncrypted($oldCiphertext));

        // get() tidak boleh mengenkripsi ulang ciphertext key lama dan mengembalikan default
        $this->assertSame('fallback', Setting::get('gemini_api_key', 'fallback'));
        $this->assertSame($oldCiphertext, DB::table('settings')->where('key', 'gemini_api_key')->value('value'));

        // Run migrasi pertama: hanya plaintext yang dienkripsi
        $this->assertSame(1, Setting::migratePlaintextSecrets());
        $encryptedEmisAfterFirstRun = DB::table('settings')->where('key', 'emis_sso_token')->value('value');
        $this->assertSame('plaintext-emis-token', decrypt($encryptedEmisAfterFirstRun));
        $this->assertSame($oldCiphertext, DB::table('settings')->where('key', 'gemini_api_key')->value('value'));

        // Run migrasi kedua: idempotent, tidak menyentuh ciphertext lama maupun yang sudah terenkripsi
        $this->assertSame(0, Setting::migratePlaintextSecrets());
        $this->assertSame($encryptedEmisAfterFirstRun, DB::table('settings')->where('key', 'emis_sso_token')->value('value'));
        $this->assertSame($oldCiphertext, DB::table('settings')->where('key', 'gemini_api_key')->value('value'));
    }
}
