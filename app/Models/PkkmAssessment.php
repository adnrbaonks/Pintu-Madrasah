<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PkkmAssessment extends Model
{
    use HasUuids;

    protected $fillable = [
        'pkkm_instrument_id',
        'pkkm_component_id',
        'self_score',
        'verified_score',
        'self_notes',
        'assessor_notes',
        'status',
        'assessed_by',
        'assessed_at',
    ];

    protected function casts(): array
    {
        return [
            'self_score' => 'float',
            'verified_score' => 'float',
            'assessed_at' => 'datetime',
        ];
    }

    public function instrument(): BelongsTo
    {
        return $this->belongsTo(PkkmInstrument::class, 'pkkm_instrument_id');
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(PkkmComponent::class, 'pkkm_component_id');
    }

    public function assessor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessed_by');
    }
}
