<?php

namespace Tests\Feature;

use App\Support\LocalTime;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class LocalTimeTest extends TestCase
{
    public function test_it_uses_configured_display_timezone(): void
    {
        config(['app.display_timezone' => 'Asia/Jakarta']);

        $this->assertSame('Asia/Jakarta', LocalTime::timezone());
    }

    public function test_it_converts_utc_to_local_time_without_mutating_source(): void
    {
        config(['app.display_timezone' => 'Asia/Jakarta']);

        $utc = Carbon::parse('2026-11-19 00:00:00', 'UTC');

        $this->assertSame('2026-11-19 07:00', LocalTime::toLocal($utc)->format('Y-m-d H:i'));
        $this->assertSame('2026-11-19 00:00', $utc->format('Y-m-d H:i'));
    }

    public function test_format_accepts_null_and_string_values(): void
    {
        config([
            'app.timezone' => 'UTC',
            'app.display_timezone' => 'Asia/Jakarta',
        ]);

        $this->assertNull(LocalTime::format(null));
        $this->assertSame('19-11-2026 07:00', LocalTime::format('2026-11-19 00:00:00', 'd-m-Y H:i'));
    }
}
