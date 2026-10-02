<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PpdbPeriod extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'academic_year_id',
        'name',
        'open_date',
        'close_date',
        'is_active',
        'is_public_form_active',
        'public_form_token',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'id' => 'string',
            'is_active' => 'boolean',
            'is_public_form_active' => 'boolean',
            'open_date' => 'date',
            'close_date' => 'date',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function ($model) {
            $model->id ??= (string) Str::uuid();
            if (empty($model->public_form_token)) {
                $model->public_form_token = Str::random(32);
            }
        });
    }

    public function academicYear()
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function registrations()
    {
        return $this->hasMany(PpdbRegistration::class);
    }

    public static function active(): ?self
    {
        return static::where('is_active', true)->first()
            ?? static::orderBy('created_at', 'desc')->first();
    }
}
