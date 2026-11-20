<?php

namespace Database\Factories;

use App\Models\Subject;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subject>
 */
class SubjectFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->bothify('MP-###'),
            'name' => 'Mata Pelajaran '.fake()->unique()->word(),
            'type' => 'umum',
            'grade_level' => fake()->numberBetween(7, 9),
            'kkm' => 70,
            'is_active' => true,
            'order' => 0,
        ];
    }
}
