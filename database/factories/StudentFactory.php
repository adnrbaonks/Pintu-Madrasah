<?php

namespace Database\Factories;

use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Student>
 */
class StudentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nisn' => fake()->unique()->numerify('##########'),
            'nik' => fake()->unique()->numerify('################'),
            'nis_local' => null,
            'kk_number' => fake()->optional()->numerify('################'),
            'name' => fake()->name(),
            'nickname' => fake()->optional()->firstName(),
            'gender' => fake()->randomElement(['L', 'P']),
            'birth_place' => fake()->city(),
            'birth_date' => fake()->dateTimeBetween('-18 years', '-10 years')->format('Y-m-d'),
            'religion' => 'Islam',
            'citizenship' => 'WNI',
            'address' => fake()->optional()->address(),
            'status' => 'active',
            'grade_level' => fake()->randomElement(['7', '8', '9']),
            'is_verified_by_ai' => false,
            'is_locked' => false,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'mutated_out',
        ]);
    }
}
