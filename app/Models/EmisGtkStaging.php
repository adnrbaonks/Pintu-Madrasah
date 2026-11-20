<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmisGtkStaging extends Model
{
    use HasFactory;

    protected $fillable = [
        'emis_gtk_id', 'nip', 'nik', 'npk', 'nuptk', 'nrg', 'peg_id',
        'full_name_with_titles', 'first_title', 'last_title',
        'gender', 'birth_place', 'birth_date', 'phone', 'email',
        'employment_status', 'civil_rank', 'civil_rank_tmt', 'tpg_status',
        'function_role', 'functional_position',
        'highest_education', 'university_name', 'study_program', 'graduation_year',
        'is_sertifikasi', 'k_aktif', 'source', 'last_seen_at',
        'raw_data', 'status', 'error_message',
    ];

    /**
     * Nama pendek untuk kompatibilitas frontend lama.
     *
     * @var array<int, string>
     */
    protected $appends = ['name'];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'civil_rank_tmt' => 'date',
            'tpg_status' => 'boolean',
            'is_sertifikasi' => 'boolean',
            'k_aktif' => 'boolean',
            'last_seen_at' => 'datetime',
            'raw_data' => 'array',
        ];
    }

    /**
     * Nama kanonik tersimpan di full_name_with_titles.
     */
    public function getNameAttribute(): ?string
    {
        return $this->full_name_with_titles;
    }
}
