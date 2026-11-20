<?php

namespace Database\Factories;

use App\Models\Staff;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Staff>
 */
class StaffFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nip' => null,
            'nik' => fake()->unique()->numerify('################'),
            'npk' => null,
            'nuptk' => null,
            'full_name_with_titles' => fake()->name(),
            'first_title' => null,
            'last_title' => null,
            'gender' => fake()->randomElement(['L', 'P']),
            'birth_place' => fake()->city(),
            'birth_date' => fake()->dateTimeBetween('-60 years', '-22 years')->format('Y-m-d'),
            'phone' => fake()->optional()->phoneNumber(),
            'email' => fake()->optional()->safeEmail(),
            'employment_status' => fake()->randomElement(['pns', 'pppk', 'honorer', 'gtt', 'ptt']),
            'civil_rank' => null,
            'civil_rank_tmt' => null,
            'tpg_status' => false,
            'functional_position' => null,
            'highest_education' => fake()->randomElement(['S1', 'S2', 'D4']),
            'university_name' => null,
            'study_program' => null,
            'graduation_year' => null,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
