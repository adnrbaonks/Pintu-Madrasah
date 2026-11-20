<?php

namespace Database\Factories;

use App\Models\AcademicYear;
use App\Models\PpdbPeriod;
use App\Models\PpdbRegistration;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PpdbRegistration>
 */
class PpdbRegistrationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ppdb_period_id' => PpdbPeriod::factory(),
            'academic_year_id' => AcademicYear::factory(),
            'registration_number' => 'PPDB-'.fake()->unique()->numerify('######'),
            'status' => PpdbRegistration::STATUS_REGISTERED,
            'name' => fake()->name(),
            'nisn' => fake()->optional()->numerify('##########'),
            'nik' => fake()->optional()->numerify('################'),
            'gender' => fake()->randomElement(['L', 'P']),
            'birth_place' => fake()->city(),
            'birth_date' => fake()->dateTimeBetween('-16 years', '-11 years')->format('Y-m-d'),
            'religion' => 'Islam',
            'address' => fake()->optional()->address(),
            'phone_number' => fake()->optional()->phoneNumber(),
            'prev_school_name' => fake()->optional()->company(),
            'father_name' => fake()->optional()->name('male'),
            'mother_name' => fake()->optional()->name('female'),
        ];
    }
}
