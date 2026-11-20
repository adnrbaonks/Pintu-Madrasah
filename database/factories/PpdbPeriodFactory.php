<?php

namespace Database\Factories;

use App\Models\AcademicYear;
use App\Models\PpdbPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PpdbPeriod>
 */
class PpdbPeriodFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $open = fake()->dateTimeBetween('-2 months', '+1 month');

        return [
            'academic_year_id' => AcademicYear::factory(),
            'name' => 'PPDB '.fake()->year(),
            'open_date' => $open->format('Y-m-d'),
            'close_date' => (clone $open)->modify('+1 month')->format('Y-m-d'),
            'is_active' => false,
            'is_public_form_active' => false,
            'public_form_token' => null,
            'description' => null,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => true,
            'is_public_form_active' => true,
            'public_form_token' => fake()->unique()->bothify('ppdb-########'),
        ]);
    }
}
