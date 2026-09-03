<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Student extends Model
{
    use SoftDeletes;

    protected $keyType    = 'string';
    public    $incrementing = false;

    protected $fillable = [
        'nisn', 'nik', 'nis_local', 'kk_number',
        'name', 'nickname', 'gender', 'birth_place', 'birth_date',
        'religion', 'citizenship', 'child_order', 'siblings_count', 'living_status',
        'address', 'rt', 'rw', 'village', 'district', 'regency', 'postal_code',
        'distance_to_school', 'transportation',
        'blood_type', 'height_cm', 'weight_kg', 'medical_history',
        'prev_school_name', 'prev_exam_number', 'graduation_year',
        'father_name', 'father_nik', 'father_job', 'father_income', 'father_phone',
        'mother_name', 'mother_nik', 'mother_job', 'mother_income', 'mother_phone',
        'guardian_name', 'guardian_nik', 'guardian_job', 'guardian_phone',
        'kip_number', 'pip_eligible', 'pkh_eligible', 'kks_eligible',
        'status', 'is_verified_by_ai', 'is_locked',
    ];

    protected function casts(): array
    {
        return [
            'id'               => 'string',
            'birth_date'       => 'date',
            'pip_eligible'     => 'boolean',
            'pkh_eligible'     => 'boolean',
            'kks_eligible'     => 'boolean',
            'is_verified_by_ai'=> 'boolean',
            'is_locked'        => 'boolean',
            'father_income'    => 'integer',
            'mother_income'    => 'integer',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();
        static::creating(fn($m) => $m->id ??= (string) Str::uuid());
    }

    // ── Relationships ─────────────────────────────────────────────────

    public function cohorts()
    {
        return $this->hasMany(StudentAcademicCohort::class);
    }

    public function currentCohort()
    {
        $activeYear = AcademicYear::active();
        return $this->hasOne(StudentAcademicCohort::class)
                    ->where('academic_year_id', $activeYear?->id);
    }

    public function documents()
    {
        return $this->hasMany(StudentDocument::class);
    }

    public function currentRombel()
    {
        return $this->currentCohort?->rombel;
    }

    // ── Selective Upsert — Aturan Field Protection ────────────────────

    /**
     * Apakah field identitas siswa ini BOLEH di-overwrite oleh data EMIS?
     * Melindungi data yang sudah terverifikasi AI atau dikunci manual.
     */
    public function isIdentityFieldProtected(): bool
    {
        return $this->is_locked || $this->is_verified_by_ai;
    }

    /**
     * Field-field yang TIDAK boleh di-overwrite saat sinkronisasi EMIS
     * jika siswa sudah terverifikasi / dikunci.
     */
    public static function protectedFields(): array
    {
        return [
            'name', 'nickname', 'birth_place', 'birth_date', 'nisn', 'nik',
            'mother_name', 'mother_nik', 'kk_number',
            'prev_school_name', 'prev_exam_number',
        ];
    }

    // ── Helpers ───────────────────────────────────────────────────────

    public function getStatusLabelAttribute(): string
    {
        return match($this->status) {
            'active'      => 'Aktif',
            'graduated'   => 'Lulus',
            'mutated_out' => 'Mutasi Keluar',
            'dropped'     => 'Dikeluarkan',
            default       => $this->status,
        };
    }

    public function getGenderLabelAttribute(): string
    {
        return $this->gender === 'L' ? 'Laki-laki' : 'Perempuan';
    }
}
