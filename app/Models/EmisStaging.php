<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmisStaging extends Model
{
    protected $fillable = [
        'nisn',
        'nik',
        'name',
        'gender',
        'rombel_name',
        'raw_data',
        'status',
    ];

    protected $casts = [
        'raw_data' => 'array',
    ];
}
