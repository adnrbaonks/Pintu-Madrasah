<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
            if (self::isEncrypted($value)) {
                $value = decrypt($value);
            } else {
                // Secret lama yang masih plaintext: otomatis dan aman migrasikan ke bentuk terenkripsi di DB
                try {
                    $setting->update(['value' => encrypt($value)]);
                } catch (\Throwable) {
                    // Fallback aman jika koneksi read-only
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
            if ($record->value !== null && $record->value !== '' && ! self::isEncrypted($record->value)) {
                $record->update(['value' => encrypt($record->value)]);
                $migrated++;
            }
        }

        return $migrated;
    }

    public static function isEncrypted(string $value): bool
    {
        try {
            decrypt($value);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function flushCache(): void
    {
        static::$cache = [];
    }
}
