<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Helper tampilan waktu.
 *
 * Database tetap menyimpan timestamp dalam UTC (config('app.timezone')).
 * Gunakan helper ini hanya saat menampilkan waktu ke pengguna agar konsisten
 * dengan zona waktu madrasah.
 */
class LocalTime
{
    public static function timezone(): string
    {
        return (string) config('app.display_timezone', 'Asia/Jakarta');
    }

    public static function now(): CarbonInterface
    {
        return Carbon::now(self::timezone());
    }

    public static function toLocal(CarbonInterface $moment): CarbonInterface
    {
        return $moment->copy()->setTimezone(self::timezone());
    }

    public static function format(CarbonInterface|string|null $value, string $format = 'd M Y H:i'): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $moment = $value instanceof CarbonInterface
            ? $value->copy()
            : Carbon::parse($value, (string) config('app.timezone', 'UTC'));

        return $moment->setTimezone(self::timezone())->translatedFormat($format);
    }
}
