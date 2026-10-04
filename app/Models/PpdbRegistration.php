<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PpdbRegistration extends Model
{
    const STATUS_REGISTERED = 'registered';
    const STATUS_RE_ENROLLED = 're_enrolled';
    const STATUS_VERIFIED = 'verified';
    const STATUS_PLACED = 'placed';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'ppdb_period_id',
        'academic_year_id',
        'registration_number',
        'status',

        // Biodata Siswa
        'name',
        'nisn',
        'nik',
        'gender',
        'birth_place',
        'birth_date',
        'religion',

        // Alamat & Kontak
        'address',
        'rt',
        'rw',
        'village',
        'district',
        'regency',
        'postal_code',
        'phone_number',

        // Asal Sekolah
        'prev_school_name',
        'prev_school_npsn',
        'graduation_year',

        // Data Orang Tua / Wali
        'father_name',
        'father_nik',
        'father_job',
        'father_income',
        'father_phone',
        'mother_name',
        'mother_nik',
        'mother_job',
        'mother_income',
        'mother_phone',
        'guardian_name',
        'guardian_nik',
        'guardian_job',
        'guardian_phone',

        // Meta & Konversi
        'registered_by',
        'converted_student_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'id' => 'string',
            'birth_date' => 'date',
            'father_income' => 'integer',
            'mother_income' => 'integer',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($model) {
            $model->id ??= (string) Str::uuid();

            if (empty($model->registration_number)) {
                $year = date('Y');
                // Loop hingga unik (count()+1 tabrakan bila ada data terhapus)
                $seq = static::whereYear('created_at', $year)->count() + 1;
                do {
                    $model->registration_number = 'PPDB-' . $year . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);
                    $seq++;
                } while (static::where('registration_number', $model->registration_number)->exists());
            }
        });

        static::saving(function ($model) {
            if ($model->name) {
                $model->name = mb_strtoupper($model->name, 'UTF-8');
            }
        });
    }

    public function period()
    {
        return $this->belongsTo(PpdbPeriod::class, 'ppdb_period_id');
    }

    public function academicYear()
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function documents()
    {
        return $this->hasMany(PpdbDocument::class, 'ppdb_registration_id');
    }

    public function convertedStudent()
    {
        return $this->belongsTo(Student::class, 'converted_student_id');
    }
}
