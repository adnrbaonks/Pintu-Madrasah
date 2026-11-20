<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AcademicYear extends Model
{
    use HasFactory;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['year', 'semester', 'is_active', 'is_locked'];

    protected function casts(): array
    {
        return ['id' => 'string', 'is_active' => 'boolean', 'is_locked' => 'boolean'];
    }

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn ($m) => $m->id ??= (string) Str::uuid());
    }

    public function rombels()
    {
        return $this->hasMany(Rombel::class);
    }

    public function cohorts()
    {
        return $this->hasMany(StudentAcademicCohort::class);
    }

    public static function active(): ?self
    {
        return static::where('is_active', true)->first();
    }

    public function getFullLabelAttribute(): string
    {
        return "{$this->year} {$this->semester}";
    }
}
