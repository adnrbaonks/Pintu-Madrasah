<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class EmisStaging extends Model
{
    protected $table = 'emis_staging';

    protected $keyType = 'string';
    public $incrementing = false;

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

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn ($m) => $m->id ??= (string) Str::uuid());
    }
}
