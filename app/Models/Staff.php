<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Staff extends Model
{
    use HasFactory, SoftDeletes;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'nip', 'nik', 'npk', 'nuptk',
        'full_name_with_titles', 'first_title', 'last_title',
        'gender', 'birth_place', 'birth_date', 'phone', 'email',
        'employment_status', 'civil_rank', 'civil_rank_tmt', 'tpg_status',
        'functional_position',
        'highest_education', 'university_name', 'study_program', 'graduation_year',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'id' => 'string',
            'birth_date' => 'date',
            'civil_rank_tmt' => 'date',
            'tpg_status' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn ($m) => $m->id ??= (string) Str::uuid());
    }

    // ── Relationships ─────────────────────────────────────────────────

    public function user()
    {
        return $this->hasOne(User::class);
    }

    public function homeroomRombels()
    {
        return $this->hasMany(Rombel::class, 'homeroom_teacher_id');
    }

    // ── Computed Attributes ───────────────────────────────────────────

    public function getDisplayNameAttribute(): string
    {
        $parts = array_filter([
            $this->first_title,
            $this->full_name_with_titles,
        ]);

        return implode(' ', $parts);
    }

    /**
     * Nama kanonik GTK. Tabel staff tidak memiliki kolom name.
     */
    public function getNameAttribute(): string
    {
        return $this->full_name_with_titles;
    }

    public function getEmploymentStatusLabelAttribute(): string
    {
        return match ($this->employment_status) {
            'pns' => 'PNS',
            'pppk' => 'PPPK',
            'honorer' => 'Honorer',
            'gtt' => 'GTT',
            'ptt' => 'PTT',
            default => $this->employment_status,
        };
    }
}
