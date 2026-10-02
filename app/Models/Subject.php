<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subject extends Model
{
    protected $fillable = ['code', 'name', 'type', 'grade_level', 'kkm', 'is_active', 'order'];

    protected function casts(): array
    {
        return [
            'grade_level' => 'integer',
            'kkm'         => 'integer',
            'is_active'   => 'boolean',
            'order'       => 'integer',
        ];
    }

    public function grades(): HasMany
    {
        return $this->hasMany(StudentGrade::class);
    }
}
