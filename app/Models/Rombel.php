<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Rombel extends Model
{
    use HasFactory;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['academic_year_id', 'class_id', 'name', 'homeroom_teacher_id'];

    protected function casts(): array
    {
        return ['id' => 'string', 'academic_year_id' => 'string', 'class_id' => 'string', 'homeroom_teacher_id' => 'string'];
    }

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn ($m) => $m->id ??= (string) Str::uuid());
    }

    public function academicYear()
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function schoolClass()
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function homeroomTeacher()
    {
        return $this->belongsTo(Staff::class, 'homeroom_teacher_id');
    }

    public function cohorts()
    {
        return $this->hasMany(StudentAcademicCohort::class);
    }
}
