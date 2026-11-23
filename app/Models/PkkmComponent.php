<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PkkmComponent extends Model
{
    use HasUuids;

    protected $fillable = [
        'pkkm_instrument_id',
        'category_code',
        'category_name',
        'code',
        'title',
        'indicator_description',
        'required_evidence_hint',
        'weight',
        'max_score',
        'sort_order',
        'pic_user_id',
    ];

    protected function casts(): array
    {
        return [
            'weight' => 'float',
            'max_score' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function instrument(): BelongsTo
    {
        return $this->belongsTo(PkkmInstrument::class, 'pkkm_instrument_id');
    }

    public function pic(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pic_user_id');
    }

    public function assessment(): HasOne
    {
        return $this->hasOne(PkkmAssessment::class, 'pkkm_component_id');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(PkkmEvidence::class, 'pkkm_component_id')->orderByDesc('created_at');
    }
}
