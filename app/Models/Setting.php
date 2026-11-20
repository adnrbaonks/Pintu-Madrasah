<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = ['key', 'value'];

    /** Memo per-request agar share props tidak menambah query berulang. */
    protected static array $cache = [];

    public static function get(string $key, $default = null)
    {
        if (array_key_exists($key, static::$cache)) {
            return static::$cache[$key];
        }
        $setting = self::where('key', $key)->first();

        return static::$cache[$key] = $setting ? $setting->value : $default;
    }

    public static function set(string $key, $value)
    {
        static::$cache[$key] = $value;

        return self::updateOrCreate(['key' => $key], ['value' => $value]);
    }

    public static function flushCache(): void
    {
        static::$cache = [];
    }
}
