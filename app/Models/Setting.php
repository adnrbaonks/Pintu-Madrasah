<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = ['key', 'value'];

    /** Secret values encrypted at rest. */
    private const SECRET_KEYS = ['gemini_api_key', 'api_key', 'emis_sso_token'];

    /** Memo per-request agar share props tidak menambah query berulang. */
    protected static array $cache = [];

    public static function get(string $key, $default = null)
    {
        if (array_key_exists($key, static::$cache)) {
            return static::$cache[$key];
        }
        $setting = self::where('key', $key)->first();
        $value = $setting?->value;

        if ($value !== null && in_array($key, self::SECRET_KEYS, true) && self::isEncrypted($value)) {
            $value = decrypt($value);
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

    private static function isEncrypted(string $value): bool
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
