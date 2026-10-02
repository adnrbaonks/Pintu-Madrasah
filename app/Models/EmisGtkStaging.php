<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmisGtkStaging extends Model
{
    protected $fillable = [
        'emis_gtk_id', 'nip', 'nik', 'npk', 'nuptk',
        'full_name_with_titles', 'first_title', 'last_title',
        'gender', 'birth_place', 'birth_date', 'phone', 'email',
        'employment_status', 'civil_rank', 'civil_rank_tmt', 'tpg_status',
        'functional_position',
        'highest_education', 'university_name', 'study_program', 'graduation_year',
        'raw_data', 'status', 'error_message'
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'civil_rank_tmt' => 'date',
            'tpg_status' => 'boolean',
            'raw_data' => 'array',
        ];
    }
}

