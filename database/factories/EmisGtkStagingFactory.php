<?php

namespace Database\Factories;

use App\Models\EmisGtkStaging;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmisGtkStaging>
 */
class EmisGtkStagingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'emis_gtk_id' => fake()->unique()->uuid(),
            'nip' => null,
            'nik' => fake()->unique()->numerify('################'),
            'npk' => null,
            'nuptk' => null,
            'nrg' => null,
            'peg_id' => fake()->numerify('############'),
            'full_name_with_titles' => mb_strtoupper(fake()->name()),
            'first_title' => null,
            'last_title' => null,
            'gender' => fake()->randomElement(['L', 'P']),
            'birth_place' => fake()->city(),
            'birth_date' => fake()->date(),
            'phone' => null,
            'email' => null,
            'employment_status' => 'pns',
            'civil_rank' => null,
            'civil_rank_tmt' => null,
            'tpg_status' => false,
            'function_role' => 'Guru',
            'functional_position' => null,
            'highest_education' => 'S1',
            'university_name' => null,
            'study_program' => null,
            'graduation_year' => null,
            'is_sertifikasi' => false,
            'k_aktif' => true,
            'source' => 'bookmarklet',
            'last_seen_at' => now(),
            'raw_data' => [],
            'status' => 'pending',
            'error_message' => null,
        ];
    }

    /**
     * Tanpa NIK/NUPTK/NIP/NPK — tidak boleh dibuatkan staff.
     */
    public function withoutIdentity(): static
    {
        return $this->state(fn (array $attributes) => [
            'nik' => null,
            'nuptk' => null,
            'nip' => null,
            'npk' => null,
        ]);
    }

    /**
     * Hanya punya NUPTK sebagai identitas.
     */
    public function nuptkOnly(): static
    {
        return $this->state(fn (array $attributes) => [
            'nik' => null,
            'nip' => null,
            'npk' => null,
            'nuptk' => fake()->unique()->numerify('################'),
        ]);
    }

    /**
     * Data wajib staff tidak lengkap.
     */
    public function incomplete(): static
    {
        return $this->state(fn (array $attributes) => [
            'gender' => null,
            'birth_place' => null,
            'birth_date' => null,
            'employment_status' => null,
        ]);
    }
}
