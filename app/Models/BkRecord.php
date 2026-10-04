<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class BkRecord extends Model
{
    use SoftDeletes;

    public const TYPES = [
        'violation'   => 'Pelanggaran Tata Tertib',
        'habit'       => 'Pembiasaan Karakter (P5-PPRA)',
        'achievement' => 'Prestasi / Apresiasi',
        'home_visit'  => 'Home Visit',
        'summon'      => 'Pemanggilan Orang Tua',
    ];

    public const VIOLATION_CATEGORIES = [
        'Terlambat', 'Bolos', 'Seragam', 'Rambut', 'HP/Gadget',
        'Merokok', 'Perkelahian', 'Menyontek', 'Ibadah', 'Kebersihan', 'Lainnya',
    ];

    public const HABIT_CATEGORIES = [
        'Beriman & Bertakwa', 'Berkebinekaan Global', 'Bergotong Royong',
        'Mandiri', 'Bernalar Kritis', 'Kreatif',
    ];

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'student_id', 'academic_year_id', 'record_date', 'type', 'category',
        'title', 'description', 'points', 'follow_up', 'status', 'reported_by',
    ];

    protected function casts(): array
    {
        return [
            'id' => 'string',
            'record_date' => 'date',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn ($m) => $m->id ??= (string) Str::uuid());
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function academicYear()
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function reporter()
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->type] ?? ucfirst($this->type);
    }
}
