<?php

namespace Database\Factories;

use App\Models\AcademicYear;
use App\Models\Rombel;
use App\Models\SchoolClass;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Rombel>
 */
class RombelFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'academic_year_id' => AcademicYear::factory(),
            'class_id' => SchoolClass::factory(),
            'name' => fake()->unique()->bothify('Kelas ##-?'),
            'homeroom_teacher_id' => null,
        ];
    }
}
