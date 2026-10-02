<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmisStaging extends Model
{
    protected $table = 'emis_staging';

    protected $fillable = [
        'source',
        'nisn',
        'nik',
        'name',
        'gender',
        'rombel_name',
        'raw_data',
        'status',
        'sync_status',
        'sync_notes',
    ];

    protected $casts = [
        'raw_data' => 'array',
    ];
}
