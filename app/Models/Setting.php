<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class Setting extends Model
{
    protected $fillable = ['key', 'value'];

    /** Secret values encrypted at rest. */
    public const SECRET_KEYS = [
        'gemini_api_key',
        'api_key',
        'emis_sso_token',
        'telegram_bot_token',
        'rdm_api_key',
    ];

    /** Memo per-request agar share props tidak menambah query berulang. */
    protected static array $cache = [];

    public static function get(string $key, $default = null)
    {
        if (array_key_exists($key, static::$cache)) {
            return static::$cache[$key];
        }
        $setting = self::where('key', $key)->first();
        $value = $setting?->value;

        if ($value !== null && in_array($key, self::SECRET_KEYS, true)) {
            $encryptionState = self::encryptionState($value);
            if ($encryptionState === 'encrypted') {
                $value = decrypt($value);
            } elseif ($encryptionState === 'invalid_ciphertext') {
                Log::warning('Setting secret uses an unavailable APP_KEY; preserving ciphertext.', ['key' => $key]);
                $value = null;
            } else {
                // Secret lama yang masih plaintext: otomatis migrasikan ke bentuk terenkripsi.
                try {
                    $setting->update(['value' => encrypt($value)]);
                } catch (\Throwable) {
                    // Fallback aman jika koneksi read-only.
                }
            }
        }

        return static::$cache[$key] = $value ?? $default;
    }

    public static function set(string $key, $value)
    {
        static::$cache[$key] = $value;

        $storedValue = $value;
        if ($value !== null && $value !== '' && in_array($key, self::SECRET_KEYS, true)) {
            $storedValue = encrypt($value);
        }

        return self::updateOrCreate(['key' => $key], ['value' => $storedValue]);
    }

    /**
     * Migrasi batch secret lama yang masih tersimpan dalam bentuk plaintext.
     */
    public static function migratePlaintextSecrets(): int
    {
        $migrated = 0;
        $records = self::whereIn('key', self::SECRET_KEYS)->get();

        foreach ($records as $record) {
            if ($record->value !== null && $record->value !== '' && self::encryptionState($record->value) === 'plaintext') {
                $record->update(['value' => encrypt($record->value)]);
                $migrated++;
            } elseif ($record->value !== null && self::encryptionState($record->value) === 'invalid_ciphertext') {
                Log::warning('Setting secret uses an unavailable APP_KEY; preserving ciphertext.', ['key' => $record->key]);
            }
        }

        return $migrated;
    }

    public static function isEncrypted(string $value): bool
    {
        return self::encryptionState($value) !== 'plaintext';
    }

    /**
     * @return 'encrypted'|'invalid_ciphertext'|'plaintext'
     */
    public static function encryptionState(string $value): string
    {
        if (! self::hasEncryptedPayloadStructure($value)) {
            return 'plaintext';
        }

        try {
            decrypt($value);

            return 'encrypted';
        } catch (DecryptException) {
            return 'invalid_ciphertext';
        }
    }

    private static function hasEncryptedPayloadStructure(string $value): bool
    {
        $decoded = base64_decode($value, true);
        if (! is_string($decoded)) {
            return false;
        }

        $payload = json_decode($decoded, true);
        if (! is_array($payload)) {
            return false;
        }

        return isset($payload['iv'], $payload['value'], $payload['mac'])
            && is_string($payload['iv'])
            && is_string($payload['value'])
            && is_string($payload['mac']);
    }

    public static function flushCache(): void
    {
        static::$cache = [];
    }
}
