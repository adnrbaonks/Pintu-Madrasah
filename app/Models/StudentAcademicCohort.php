<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class StudentAcademicCohort extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;
    protected $fillable = ['student_id','academic_year_id','semester','rombel_id','attendance_number','status'];
    protected function casts(): array { return ['id'=>'string','student_id'=>'string','academic_year_id'=>'string','rombel_id'=>'string']; }
    protected static function boot(): void { parent::boot(); static::creating(fn($m) => $m->id ??= (string) Str::uuid()); }
    public function student()      { return $this->belongsTo(Student::class); }
    public function academicYear() { return $this->belongsTo(AcademicYear::class); }
    public function rombel()       { return $this->belongsTo(Rombel::class); }
}
