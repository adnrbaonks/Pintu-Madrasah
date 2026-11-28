<?php

namespace Database\Factories;

use App\Models\EmisImportSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmisImportSession>
 */
class EmisImportSessionFactory extends Factory
{
    protected $model = EmisImportSession::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'entity_type' => 'staff',
            'status' => EmisImportSession::STATUS_OPEN,
            'owner_key' => 'user:'.fake()->uuid(),
            'total_pages' => 1,
            'source' => 'bookmarklet',
            'started_at' => now(),
            'expires_at' => now()->addHours(2),
        ];
    }
}
