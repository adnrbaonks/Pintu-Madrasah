<?php

namespace App\Http\Controllers;

use App\Models\Student;
use App\Models\Rombel;
use App\Models\AcademicYear;
use App\Models\PpdbRegistration;
use App\Models\Setting;
use App\Models\StudentAcademicCohort;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Barryvdh\DomPDF\Facade\Pdf;

class StudentController extends Controller
{
    /**
     * Display a listing of the students.
     */
    public function index(Request $request)
    {
        $query = Student::query()
            ->with(['currentCohort.rombel', 'currentCohort.academicYear']);

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('nisn', 'like', "%{$search}%")
                  ->orWhere('nik', 'like', "%{$search}%");
            });
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($rombelId = $request->input('rombel')) {
            $query->whereHas('currentCohort', function ($q) use ($rombelId) {
                $q->where('rombel_id', $rombelId);
            });
        }

        if ($assistance = $request->input('assistance')) {
            if ($assistance === 'pip') $query->where('pip_eligible', true);
            if ($assistance === 'pkh') $query->where('pkh_eligible', true);
            if ($assistance === 'kks') $query->where('kks_eligible', true);
        }

        if ($verification = $request->input('verification')) {
            if ($verification === 'verified') $query->where('is_verified_by_ai', true);
            if ($verification === 'unverified') $query->where('is_verified_by_ai', false);
        }

        $students = $query->orderBy('name')->paginate(10)->withQueryString();

        $activeYearId = \App\Models\AcademicYear::active()?->id;
        $rombels = $activeYearId ? \App\Models\Rombel::where('academic_year_id', $activeYearId)->orderBy('name')->get(['id', 'name']) : collect();

        return Inertia::render('Students/Index', [
            'students' => $students,
            'rombels' => $rombels,
            'filters' => $request->only(['search', 'status', 'rombel', 'assistance', 'verification']),
        ]);
    }

    /**
     * Update Student Status (Mutation/Graduation)
     */
    public function updateStatus(Request $request, Student $student)
    {
        $validated = $request->validate([
            'status' => 'required|in:active,graduated,mutated_out,dropped',
        ]);

        $student->update($validated);

        // Jika statusnya pindah/keluar/lulus, kita juga harus menghentikan status aktifnya di cohort terakhir
        if ($validated['status'] !== 'active') {
            if ($cohort = $student->currentCohort) {
                $cohort->update(['status' => 'inactive']);
            }
            // Sinkron balik ke register mutasi agar tidak timpang:
            // status non-aktif manual wajib punya jejak record mutasi.
            if (in_array($validated['status'], ['mutated_out', 'dropped'], true)) {
                $exists = \App\Models\StudentMutation::where('student_id', $student->id)
                    ->where('status', 'completed')
                    ->exists();
                if (! $exists) {
                    \App\Models\StudentMutation::create([
                        'student_id' => $student->id,
                        'name' => $student->name,
                        'nisn' => $student->nisn,
                        'type' => 'out',
                        'mutation_date' => now()->toDateString(),
                        'reason' => 'Dicatat manual dari Master Siswa',
                        'status' => 'completed',
                    ]);
                }
            }
        } else {
            // Jika dikembalikan ke aktif
             if ($cohort = $student->cohorts()->latest()->first()) {
                $cohort->update(['status' => 'active']);
            }
        }

        return back()->with('success', 'Status siswa berhasil diperbarui.');
    }

    /**
     * Display the specified student.
     */
    public function show(Student $student)
    {
        $student->load([
            'cohorts.rombel', 
            'cohorts.academicYear', 
            'documents',
        ]);

        // Riwayat perubahan data (20 terbaru)
        $activityLogs = \App\Models\ActivityLog::where('model_type', Student::class)
            ->where('model_id', $student->id)
            ->with('user')
            ->latest('created_at')
            ->limit(5)
            ->get();

        $student->current_rombel_name = $student->currentRombel()?->name;

        return Inertia::render('Students/Show', [
            'student'      => $student,
            'activityLogs' => $activityLogs,
        ]);
    }

    /**
     * Upload foto profil siswa.
     */
    public function uploadPhoto(Request $request, Student $student, \App\Services\FileCompressionService $compressionService)
    {
        $request->validate([
            'photo' => 'required|image|mimes:jpg,jpeg,png|max:5120', // max 5MB, will compress
        ]);

        // Hapus foto lama jika ada
        if ($student->profile_photo && \Illuminate\Support\Facades\Storage::disk('local')->exists($student->profile_photo)) {
            \Illuminate\Support\Facades\Storage::disk('local')->delete($student->profile_photo);
        }

        $file = $request->file('photo');
        $filePath = $file->getPathname();
        $mimeType = $file->getMimeType();
        $extension = $file->getClientOriginalExtension();

        // 1. Kompres file
        $compressedPath = $compressionService->compressIfNeeded($filePath, $mimeType, $extension);
        
        // 2. Simpan di folder privat lokal
        $newFilename = $student->id . '_' . time() . '.' . $extension;
        $archivePath = 'archives/photo/' . $newFilename;
        \Illuminate\Support\Facades\Storage::disk('local')->put($archivePath, file_get_contents($compressedPath));

        $student->update([
            'profile_photo' => $archivePath,
            'photo_upload_status' => 'pending',
            'gdrive_photo_id' => null,
            'gdrive_photo_url' => null,
            'photo_upload_retries' => 0,
        ]);

        // 3. Dispatch background job untuk upload ke GDrive
        \App\Jobs\UploadToGoogleDriveJob::dispatch('photo', $student->id);

        return back()->with('success', 'Foto profil berhasil diperbarui dan sedang diunggah ke Google Drive.');
    }

    /**
     * Hapus foto profil siswa.
     */
    public function deletePhoto(Student $student)
    {
        if ($student->profile_photo) {
            \Illuminate\Support\Facades\Storage::disk('local')->delete($student->profile_photo);
            $student->update([
                'profile_photo' => null,
                'gdrive_photo_id' => null,
                'gdrive_photo_url' => null,
                'photo_upload_status' => null,
            ]);
        }

        return back()->with('success', 'Foto profil berhasil dihapus.');
    }

    /**
     * Print Student Biodata to PDF.
     */
    public function printBiodata(Student $student)
    {
        $student->load(['currentCohort.rombel', 'currentCohort.academicYear']);

        $verification = \App\Models\DocumentVerification::issue(
            \App\Models\DocumentVerification::TYPE_BIODATA,
            $student,
            [
                'madrasah' => \App\Models\Setting::get('madrasah_name', env('MADRASAH_NAME', 'MTs')),
                'nama' => $student->name,
                'nisn' => $student->nisn,
                'nik' => $student->nik,
                'rombel' => $student->currentCohort?->rombel?->name,
                'status' => $student->status_label,
                'tanggal_cetak' => now()->format('d-m-Y'),
            ]
        );

        $pdf = Pdf::loadView('pdf.student-biodata', [
            'student' => $student,
            'madrasahName' => Setting::get('madrasah_name', env('MADRASAH_NAME', 'MTs Negeri Contoh')),
            'madrasahAddress' => Setting::get('school_address', env('MADRASAH_ADDRESS', 'Jl. Contoh No. 1')),
            'qrDataUri' => \App\Support\Qr::dataUri($verification->verifyUrl()),
            'verifyUrl' => $verification->verifyUrl(),
        ]);

        return $pdf->stream("Biodata_{$student->nisn}_{$student->name}.pdf");
    }

    /**
     * Print Exam Cards for a specific Rombel.
     */
    public function printExamCards(Request $request)
    {
        $rombelId = $request->input('rombel_id');
        
        if (!$rombelId) {
            return redirect()->back()->with('error', 'Rombel ID is required.');
        }

        $rombel = Rombel::with(['cohorts' => function($q) {
            $q->where('status', 'active')->with('student');
        }, 'academicYear'])->findOrFail($rombelId);

        $students = $rombel->cohorts->pluck('student')->sortBy('name');

        $pdf = Pdf::loadView('pdf.exam-cards', [
            'rombel' => $rombel,
            'students' => $students,
            'madrasahName' => Setting::get('madrasah_name', env('MADRASAH_NAME', 'MTs Negeri Contoh')),
            'examName' => $request->input('exam_name', 'Ujian Akhir Semester'),
        ]);

        // A4 paper, portrait
        $pdf->setPaper('a4', 'portrait');

        return $pdf->stream("Kartu_Ujian_{$rombel->name}.pdf");
    }

    /**
     * Show Seat Layout Builder (Denah Kelas)
     */
    public function seatLayout(Request $request)
    {
        $rombelId = $request->input('rombel_id');
        $rombels = Rombel::all();
        $students = [];
        $activeRombel = null;

        if ($rombelId) {
            $activeRombel = Rombel::find($rombelId);
            $students = Student::whereHas('cohorts', function ($q) use ($rombelId) {
                $q->where('rombel_id', $rombelId)->where('status', 'active');
            })->orderBy('name')->get();
        }


        return Inertia::render('Students/SeatLayout', [
            'rombels' => $rombels,
            'students' => $students,
            'activeRombel' => $activeRombel,
        ]);
    }
    public function exportData(Request $request)
    {
        $fileName = 'Data_Siswa_Madrasah_' . date('Y-m-d') . '.csv';

        // Headers untuk memandu Browser mengunduh file sebagai CSV
        $headers = [
            "Content-type"        => "text/csv",
            "Content-Disposition" => "attachment; filename=$fileName",
            "Pragma"              => "no-cache",
            "Cache-Control"       => "must-revalidate, post-check=0, pre-check=0",
            "Expires"             => "0"
        ];

        // Buat stream callback yang akan menulis baris-baris CSV langsung ke Output
        $callback = function() use ($request) {
            $file = fopen('php://output', 'w');
            
            // Tulis UTF-8 BOM untuk kompatibilitas Microsoft Excel
            fputs($file, "\xEF\xBB\xBF");

            // Header kolom sesuai format Excel
            fputcsv($file, [
                'No', 'KELAS', 'NO ABSEN', 'NISM', 'NISN', 'NAMA', 'L/P', 
                'Nomor Induk Kependudukan (NIK) Siswa', 'Tempat', 'Tanggal Lahir', 'Asal Sekolah Dasar', 'Status'
            ], ';');

            $query = Student::query()->with(['currentCohort.rombel']);
            
            if ($status = $request->input('status')) {
                $query->where('status', $status);
            }
            if ($search = $request->input('search')) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('nisn', 'like', "%{$search}%")
                      ->orWhere('nik', 'like', "%{$search}%");
                });
            }

            $students = $query->orderBy('name')->get();

            $no = 1;
            foreach ($students as $student) {
                fputcsv($file, [
                    $no++,
                    $student->currentCohort?->rombel?->name ?? '-',
                    '-', // NO ABSEN belum di-mapping
                    $student->nis_local,
                    $student->nisn,
                    $student->name,
                    $student->gender,
                    $student->nik,
                    $student->birth_place,
                    $student->birth_date ? $student->birth_date->format('d/m/Y') : '',
                    $student->prev_school_name,
                    $student->status_label
                ], ';');
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Export multiple types of reports as PDF
     */
    public function exportPdf(Request $request)
    {
        $type = $request->input('type', 'daftar_siswa'); // daftar_siswa, daftar_hadir, form_nilai
        
        $query = Student::query()->with(['currentCohort.rombel']);
        
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('nisn', 'like', "%{$search}%")
                  ->orWhere('nik', 'like', "%{$search}%");
            });
        }

        $students = $query->orderBy('name')->get();

        $pdf = Pdf::loadView('pdf.student-report', [
            'students' => $students,
            'type' => $type,
            'madrasahName' => Setting::get('madrasah_name', env('MADRASAH_NAME', 'MTs Negeri Contoh')),
        ]);

        $pdf->setPaper('a4', 'landscape'); // Format laporan tabel lebih cocok landscape

        return $pdf->stream("Laporan_{$type}_" . date('Ymd') . ".pdf");
    }

    /**
     * Sinkronisasi detail individu dari EMIS.
     */
    public function edit(Student $student)
    {
        $student->current_rombel_name = $student->currentRombel()?->name;
        return Inertia::render('Students/Edit', [
            'student' => $student
        ]);
    }

    public function update(Request $request, Student $student)
    {
        $validated = $request->validate([
            'name'        => 'required|string|max:255',
            'nis_local'   => 'nullable|string|max:20',
            'nisn'        => 'nullable|string|max:20',
            'nik'         => 'nullable|string|max:20',
            'gender'      => 'required|in:L,P',
            'birth_place' => 'required|string|max:100',
            'birth_date'  => 'required|date',
            'religion'    => 'required|string|max:50',

            // Alamat
            'address'     => 'nullable|string|max:500',
            'rt'          => 'nullable|string|max:10',
            'rw'          => 'nullable|string|max:10',
            'village'     => 'nullable|string|max:100',
            'district'    => 'nullable|string|max:100',
            'regency'     => 'nullable|string|max:100',
            'postal_code' => 'nullable|string|max:10',

            // Ayah
            'father_name'   => 'nullable|string|max:255',
            'father_nik'    => 'nullable|string|max:20',
            'father_job'    => 'nullable|string|max:100',
            'father_income' => 'nullable|integer',
            'father_phone'  => 'nullable|string|max:30',

            // Ibu
            'mother_name'   => 'nullable|string|max:255',
            'mother_nik'    => 'nullable|string|max:20',
            'mother_job'    => 'nullable|string|max:100',
            'mother_income' => 'nullable|integer',
            'mother_phone'  => 'nullable|string|max:30',

            // Wali
            'guardian_name'  => 'nullable|string|max:255',
            'guardian_nik'   => 'nullable|string|max:20',
            'guardian_job'   => 'nullable|string|max:100',
            'guardian_phone' => 'nullable|string|max:30',

            // Bantuan Sosial & Ekstra
            'kip_number' => 'nullable|string|max:50',
            'kk_number'  => 'nullable|string|max:20',
            'prev_school_name' => 'nullable|string|max:255',
            'graduation_year'  => 'nullable|integer',
            'distance_to_school' => 'nullable|string|max:50',
            'transportation' => 'nullable|string|max:50',
            'enrollment_type' => 'nullable|string|max:50',
            'pip_eligible' => 'nullable|boolean',
            'pkh_eligible' => 'nullable|boolean',
            'kks_eligible' => 'nullable|boolean',
        ]);

        // Auto-uppercase nama siswa dan orang tua/wali
        $nameFields = ['name', 'father_name', 'mother_name', 'guardian_name'];
        foreach ($nameFields as $field) {
            if (!empty($validated[$field])) {
                $validated[$field] = strtoupper($validated[$field]);
            }
        }

        $student->update($validated);

        return back()->with('success', 'Data siswa berhasil diperbarui.');
    }

    public function previewEmisDetail(Student $student, \App\Services\EmisService $emis)
    {
        if (!$student->emis_student_id) {
            return response()->json(['message' => 'Siswa ini tidak memiliki ID EMIS. Lakukan Tarik Data EMIS bulk terlebih dahulu atau sinkron ulang.'], 400);
        }

        if (!$emis->hasActiveEmisToken()) {
            return response()->json(['message' => 'Sesi EMIS kedaluwarsa. Silakan login EMIS terlebih dahulu di Pengaturan.'], 400);
        }

        $detail = $emis->fetchStudentDetail($student->emis_student_id);

        if (!$detail) {
            return response()->json(['message' => 'Gagal menarik data dari server EMIS. Pastikan koneksi lancar.'], 400);
        }

        // Tarik learning activity untuk data asal sekolah
        $prevSchoolName = null;
        try {
            $encId = $emis->encryptEmisId($student->emis_student_id);
            $token = cache('emis:token:siswa');
            $caFile = '/etc/ssl/certs/ca-certificates.crt';
            $laRes = \Illuminate\Support\Facades\Http::withOptions(['verify' => file_exists($caFile) ? $caFile : true])
                ->withToken($token)
                ->timeout(30)
                ->get("https://api-emis.kemenag.go.id/v1/students/students/{$encId}/learning-activities/list");
            if ($laRes->ok()) {
                $results = $laRes->json('results') ?? [];
                
                // Cari sekolah pertama di riwayat yang BUKAN MTsN 5 Sumedang
                // (Berlaku untuk jenjang SD/MI sebelumnya, atau sekolah asal jika pindahan)
                $prevSchool = collect($results)->first(function ($item) {
                    $name = strtoupper($item['institution_name'] ?? '');
                    return !str_contains($name, 'MTSN 5 SUMEDANG');
                });
                
                $prevSchoolName = $prevSchool['institution_name'] ?? null;
            }
        } catch (\Throwable $e) {
            // Jika gagal tarik asal sekolah, lanjutkan tanpa error
        }

        // Mapping Data Detail EMIS ke kolom Model Student.
        // Jika data EMIS null atau '-', pertahankan data lokal.
        $parents = $detail['parents'] ?? [];

        // Helper: kembalikan null jika nilai EMIS adalah '-' atau kosong
        $emv = fn($val) => (isset($val) && $val !== '-' && $val !== '') ? $val : null;

        // Helper: ubah range penghasilan EMIS ("800.001 - 1.200.000") ke nilai tengah integer
        $parseEmisIncome = function(?array $incomeObj) use ($student): ?int {
            $name = $incomeObj['name'] ?? null;
            if (!$name || $name === '-') return null;
            // Hapus titik ribuan, ambil angka-angka dari string
            $clean = preg_replace('/[^0-9,\-]/', '', str_replace('.', '', $name));
            $parts = array_map('intval', preg_split('/\s*[,\-]\s*/', $clean));
            $parts = array_filter($parts, fn($v) => $v > 0);
            if (empty($parts)) return null;
            return (int) round(array_sum($parts) / count($parts));
        };

        $proposedData = [
            'nisn' => $emv($detail['nisn']) ?? $student->nisn,
            'nik'  => $emv($detail['nik'])  ?? $student->nik,
            // 'name' => Jangan timpa nama; data Dukcapil lokal lebih akurat.
            'gender'      => $emis->parseGender($detail['m_gender'] ?? $detail['gender'] ?? $detail['jenis_kelamin'] ?? $detail['jk'] ?? null) ?? $student->gender,
            'birth_place' => $emv($detail['birth_place']) ?? $student->birth_place,
            'birth_date'  => $emv($detail['birth_date'])  ?? $student->birth_date,

            'child_order'    => $emv($detail['child_of_num']) ?? $student->child_order,
            'siblings_count' => $emv($detail['siblings_num']) ?? $student->siblings_count,

            // Alamat
            'address'     => $emv($detail['address'])         ?? $student->address,
            'rt'          => $emv($detail['rt'])              ?? $student->rt,
            'rw'          => $emv($detail['rw'])              ?? $student->rw,
            'postal_code' => $emv((string)($detail['postal_code_num'] ?? null)) ?? $student->postal_code,
            'village'     => $emv($detail['m_sub_district']['name'] ?? null) ?? $student->village,
            'district'    => $emv($detail['m_district']['name']     ?? null) ?? $student->district,
            'regency'     => $emv($detail['m_city']['name']         ?? null) ?? $student->regency,
            'distance_to_school' => $emv($detail['m_residence_distance']['name'] ?? null) ?? $student->distance_to_school,
            'transportation'     => $emv($detail['m_transportation']['name'] ?? null) ?? $student->transportation,

            // Ayah
            'father_name'   => $emv($parents['father_full_name']    ?? null) ?? $student->father_name,
            'father_nik'    => $emv($parents['father_nik']          ?? null) ?? $student->father_nik,
            'father_job'    => $emv($parents['father_job']['name']  ?? $parents['father_m_job'] ?? null) ?? $student->father_job,
            'father_phone'  => $emv($parents['father_phone_number'] ?? null) ?? $student->father_phone,
            'father_income' => $parseEmisIncome($parents['father_average_income_per_month'] ?? null) ?? $student->father_income,

            // Ibu
            'mother_name'   => $emv($parents['mother_full_name']    ?? null) ?? $student->mother_name,
            'mother_nik'    => $emv($parents['mother_nik']          ?? null) ?? $student->mother_nik,
            'mother_job'    => $emv($parents['mother_job']['name']  ?? $parents['mother_m_job'] ?? null) ?? $student->mother_job,
            'mother_phone'  => $emv($parents['mother_phone_number'] ?? null) ?? $student->mother_phone,
            'mother_income' => $parseEmisIncome($parents['mother_average_income_per_month'] ?? null) ?? $student->mother_income,

            // Wali
            'guardian_name'  => $emv($parents['wali_full_name']    ?? null) ?? $student->guardian_name,
            'guardian_nik'   => $emv($parents['wali_nik']          ?? null) ?? $student->guardian_nik,
            'guardian_job'   => $emv($parents['wali_job']['name']  ?? null) ?? $student->guardian_job,
            'guardian_phone' => $emv($parents['wali_phone_number'] ?? null) ?? $student->guardian_phone,

            // KK & Bansos
            'kk_number'    => $emv($detail['kk_num'] ?? $detail['no_kk'] ?? $detail['nomor_kk'] ?? $parents['no_kk'] ?? $parents['nomor_kk'] ?? $detail['family_card_num'] ?? $detail['kk_number'] ?? null) ?? $student->kk_number,
            'kip_number'   => $emv($detail['nomor_kip'] ?? $detail['kip_num'] ?? null) ?? $student->kip_number,
            'pip_eligible' => filter_var($detail['is_pip'] ?? (!empty($detail['nomor_kip']) || !empty($detail['kip_num'])), FILTER_VALIDATE_BOOLEAN) ?: $student->pip_eligible,
            'pkh_eligible' => filter_var(!empty($parents['father_pkh_number']) || !empty($parents['mother_pkh_number']) || !empty($parents['wali_pkh_number']) || !empty($detail['pkh_family']) || !empty($detail['is_pkh']), FILTER_VALIDATE_BOOLEAN) ?: $student->pkh_eligible,
            'kks_eligible' => filter_var(!empty($parents['father_kks_number']) || !empty($parents['mother_kks_number']) || !empty($parents['wali_kks_number']) || !empty($detail['is_kks']), FILTER_VALIDATE_BOOLEAN) ?: $student->kks_eligible,

            // Asal Sekolah
            'prev_school_name' => $emv($prevSchoolName) ?? $student->prev_school_name,
        ];

        // Tentukan Enrollment Type berdasarkan logika user
        $currentLevel = $detail['m_level_id'] ?? $detail['tingkat_kelas_id'] ?? null;
        $isSameSchool = $prevSchoolName && str_contains(strtoupper($prevSchoolName), 'MTSN 5 SUMEDANG');
        $enrollmentType = $student->enrollment_type;

        if ($prevSchoolName) {
            if (!$isSameSchool) {
                // Dari luar jenjang/sekolah
                if ($currentLevel == 7) {
                    $enrollmentType = 'Siswa Baru';
                } else {
                    $enrollmentType = 'Pindahan';
                }
            } else {
                // Dari sekolah yang sama
                $enrollmentType = 'Naik Kelas';
            }
        } else {
            // Jika tidak ada riwayat sekolah sebelumnya di EMIS, kita tebak dari kelas atau deskripsi status
            if ($currentLevel == 7) {
                $enrollmentType = 'Siswa Baru';
            } elseif ($currentLevel == 8 || $currentLevel == 9) {
                $enrollmentType = 'Pindahan'; // Kemungkinan pindahan jika tidak ada riwayat historis sebelumnya
            }
        }
        
        // Ambil dari deskripsi status EMIS jika tersedia (misal: "Siswa Baru")
        if (isset($results) && count($results) > 0 && !empty($results[0]['status_description'])) {
            $desc = $results[0]['status_description'];
            if (str_contains(strtolower($desc), 'baru')) $enrollmentType = 'Siswa Baru';
            if (str_contains(strtolower($desc), 'pindah')) $enrollmentType = 'Pindahan';
            if (str_contains(strtolower($desc), 'naik')) $enrollmentType = 'Naik Kelas';
        }
        
        // Khusus jika status kelulusan sudah diatur EMIS atau aktif di tingkat akhir
        if (($detail['status_siswa'] ?? '') === 'Lulus' || $student->status === 'graduated') {
            $enrollmentType = 'Lulus';
        }

        $proposedData['enrollment_type'] = $enrollmentType;

        $changes = [];
        $labels = [
            'nisn' => 'NISN', 'nik' => 'NIK', 'gender' => 'Jenis Kelamin',
            'birth_place' => 'Tempat Lahir', 'birth_date' => 'Tanggal Lahir',
            'child_order' => 'Anak Ke-', 'siblings_count' => 'Jml Saudara',
            'address' => 'Alamat', 'rt' => 'RT', 'rw' => 'RW',
            'postal_code' => 'Kode Pos', 'village' => 'Kelurahan',
            'district' => 'Kecamatan', 'regency' => 'Kabupaten',
            'distance_to_school' => 'Jarak ke Madrasah', 'transportation' => 'Transportasi',
            'enrollment_type' => 'Status Masuk',
            'father_name' => 'Nama Ayah', 'father_nik' => 'NIK Ayah',
            'father_job' => 'Pekerjaan Ayah', 'father_phone' => 'No. HP Ayah', 'father_income' => 'Gaji Ayah',
            'mother_name' => 'Nama Ibu', 'mother_nik' => 'NIK Ibu',
            'mother_job' => 'Pekerjaan Ibu', 'mother_phone' => 'No. HP Ibu', 'mother_income' => 'Gaji Ibu',
            'guardian_name' => 'Nama Wali', 'guardian_nik' => 'NIK Wali',
            'guardian_job' => 'Pekerjaan Wali', 'guardian_phone' => 'No. HP Wali',
            'kk_number' => 'No. KK', 'kip_number' => 'No. KIP', 'prev_school_name' => 'Asal Sekolah',
            'pip_eligible' => 'Penerima PIP', 'pkh_eligible' => 'Penerima PKH', 'kks_eligible' => 'Penerima KKS',
        ];

        // Helper format nilai boolean agar lebih mudah dibaca
        $booleanFields = ['pip_eligible', 'pkh_eligible', 'kks_eligible'];

        foreach ($proposedData as $key => $newValue) {
            $oldValue = $student->$key;
            if ($oldValue instanceof \Carbon\Carbon) {
                $oldValue = $oldValue->format('Y-m-d');
            }

            // Format nilai boolean menjadi label yang ramah pengguna
            if (in_array($key, $booleanFields)) {
                $oldStr = (bool)$oldValue ? 'Ya (Penerima)' : 'Tidak';
                $newStr = (bool)$newValue ? 'Ya (Penerima)' : 'Tidak';
            } else {
                $oldStr = trim((string)$oldValue);
                $newStr = trim((string)$newValue);
            }

            if ($oldStr !== $newStr) {
                $changes[] = [
                    'field' => $key,
                    'label' => $labels[$key] ?? $key,
                    'old'   => $oldStr ?: '(Kosong)',
                    'new'   => $newStr ?: '(Kosong)',
                ];
            }
        }

        return response()->json([
            'success' => true,
            'changes' => $changes,
            'update_data' => $proposedData,
        ]);
    }

    public function commitEmisDetail(Request $request, Student $student)
    {
        $updateData = $request->input('update_data', []);
        if (!empty($updateData)) {
            $student->update($updateData);
        }
        return back()->with('success', 'Detail siswa berhasil diperbarui dari EMIS!');
    }

    public function generateNismView()
    {
        $studentsWithoutNismCount = Student::where('status', 'active')
            ->where(function($q) {
                $q->whereNull('nis_local')->orWhere('nis_local', '');
            })
            ->count();

        return Inertia::render('Students/GenerateNism', [
            'studentsWithoutNismCount' => $studentsWithoutNismCount,
        ]);
    }

    public function generateNism()
    {
        $nsm = \App\Models\Setting::where('key', 'nsm')->value('value');
        if (!$nsm) {
            return back()->with('error', 'NSM belum diatur di pengaturan aplikasi.');
        }

        $students = Student::whereNull('nis_local')->orWhere('nis_local', '')->get();
        $generated = 0;

        foreach ($students as $student) {
            $firstCohort = $student->cohorts()->with('academicYear')->orderBy('created_at', 'asc')->first();
            if (!$firstCohort || !$firstCohort->academicYear) continue;

            $yearString = $firstCohort->academicYear->year; // e.g. "2026/2027"
            $yearParts = explode('/', $yearString);
            if (count($yearParts) < 1) continue;
            
            $entryYear = substr($yearParts[0], -2); // "26"
            $prefix = $nsm . $entryYear;
            
            $lastStudent = Student::where('nis_local', 'like', $prefix . '%')
                ->orderBy('nis_local', 'desc')
                ->first();

            $urut = 1;
            if ($lastStudent && $lastStudent->nis_local) {
                $lastUrut = (int) substr($lastStudent->nis_local, 14, 4);
                $urut = $lastUrut + 1;
            }

            $nism = $prefix . str_pad($urut, 4, '0', STR_PAD_LEFT);
            $student->update(['nis_local' => $nism]);
            $generated++;
        }

        return back()->with('success', "$generated NISM berhasil di-generate secara otomatis sesuai urutan.");
    }

    /**
     * Halaman Penempatan Rombel dari PPDB + Siswa EMIS Tanpa Rombel.
     * Menampilkan:
     * 1. Pendaftar PPDB terverifikasi (belum ditempatkan)
     * 2. Siswa dari EMIS yang belum punya rombel di tahun ajaran aktif
     */
    public function placement(Request $request)
    {
        $activeYear = AcademicYear::active();

        // 1. Pendaftar PPDB yang siap ditempatkan (status verified)
        $verifiedRegistrations = PpdbRegistration::where('status', PpdbRegistration::STATUS_VERIFIED)
            ->orderBy('name', 'asc')
            ->get();

        // 2. Siswa dari EMIS yang BELUM punya rombel di tahun ajaran aktif
        $studentIdsWithCohort = StudentAcademicCohort::where('academic_year_id', $activeYear?->id)
            ->pluck('student_id')
            ->toArray();

        $unassignedStudents = Student::where('status', 'active')
            ->whereNotNull('emis_student_id')
            ->whereNotIn('id', $studentIdsWithCohort)
            ->orderBy('name', 'asc')
            ->get();

        // Tentukan tingkat siswa dari riwayat cohort terakhir, fallback ke student.grade_level
        foreach ($unassignedStudents as $student) {
            $lastCohort = StudentAcademicCohort::where('student_id', $student->id)
                ->with('rombel.schoolClass')
                ->orderBy('academic_year_id', 'desc')
                ->first();
            $student->grade_level = $lastCohort?->rombel?->schoolClass?->grade_level ?? $student->grade_level;
        }

        // Pendaftar yang sudah ditempatkan (status placed)
        $placedRegistrations = PpdbRegistration::where('status', PpdbRegistration::STATUS_PLACED)
            ->with(['convertedStudent.cohorts.rombel'])
            ->orderBy('updated_at', 'desc')
            ->take(20)
            ->get();

        // Daftar Rombel untuk tahun ajaran aktif (semua tingkat)
        $rombels = [];
        if ($activeYear) {
            $rombels = Rombel::where('academic_year_id', $activeYear->id)
                ->with('schoolClass')
                ->withCount('cohorts')
                ->with(['cohorts.student' => function ($q) {
                    $q->select('id', 'gender');
                }])
                ->orderBy('name', 'asc')
                ->get()
                ->map(function ($rombel) {
                    $maxCapacity = 32;
                    $current = $rombel->cohorts_count;
                    $maleCount = $rombel->cohorts->filter(fn($c) => $c->student?->gender === 'L')->count();
                    $femaleCount = $current - $maleCount;
                    return [
                        'id' => $rombel->id,
                        'name' => $rombel->name,
                        'grade_level' => $rombel->schoolClass?->grade_level ?? '-',
                        'current_students' => $current,
                        'male_count' => $maleCount,
                        'female_count' => $femaleCount,
                        'max_capacity' => $maxCapacity,
                        'remaining_capacity' => max(0, $maxCapacity - $current),
                        'is_full' => $current >= $maxCapacity,
                    ];
                });
        }

        return Inertia::render('Students/Placement', [
            'activeYear' => $activeYear,
            'verifiedRegistrations' => $verifiedRegistrations,
            'unassignedStudents' => $unassignedStudents,
            'placedRegistrations' => $placedRegistrations,
            'rombels' => $rombels,
        ]);
    }

    /**
     * Tempatkan pendaftar PPDB / Siswa EMIS ke Rombel.
     * Mendukung 2 jenis input: registration_ids[] dan student_ids[].
     */
    public function assignRombel(Request $request)
    {
        $request->validate([
            'registration_ids' => 'nullable|array',
            'registration_ids.*' => 'exists:ppdb_registrations,id',
            'student_ids' => 'nullable|array',
            'student_ids.*' => 'exists:students,id',
            'rombel_id' => 'required|exists:rombels,id',
        ]);

        $rombel = Rombel::with('academicYear')->findOrFail($request->rombel_id);
        $academicYear = $rombel->academicYear ?? AcademicYear::active();

        if (!$academicYear) {
            return back()->with('error', 'Tahun ajaran aktif tidak ditemukan.');
        }

        $registrationIds = $request->registration_ids ?? [];
        $studentIds = $request->student_ids ?? [];
        $totalToAssign = count($registrationIds) + count($studentIds);

        if ($totalToAssign === 0) {
            return back()->with('error', 'Tidak ada siswa yang dipilih.');
        }

        $currentCount = StudentAcademicCohort::where('rombel_id', $rombel->id)
            ->where('academic_year_id', $academicYear->id)
            ->count();

        if (($currentCount + $totalToAssign) > 32) {
            return back()->with('error', "Kapasitas Rombel {$rombel->name} tidak mencukupi. Saat ini ada {$currentCount} siswa, maksimal 32.");
        }

        $successCount = 0;

        DB::transaction(function () use ($registrationIds, $studentIds, $rombel, $academicYear, &$successCount) {
            // Proses pendaftar PPDB
            if (!empty($registrationIds)) {
                $registrations = PpdbRegistration::whereIn('id', $registrationIds)
                    ->whereIn('status', [PpdbRegistration::STATUS_VERIFIED, PpdbRegistration::STATUS_RE_ENROLLED])
                    ->get();

                foreach ($registrations as $reg) {
                    $student = null;
                    if (!empty($reg->nisn)) {
                        $student = Student::where('nisn', $reg->nisn)->first();
                    }
                    if (!$student && !empty($reg->nik)) {
                        $student = Student::where('nik', $reg->nik)->first();
                    }

                    if (!$student) {
                        $student = Student::create([
                            'name' => mb_strtoupper($reg->name, 'UTF-8'),
                            'nisn' => $reg->nisn,
                            'nik' => $reg->nik,
                            'gender' => $reg->gender,
                            'birth_place' => $reg->birth_place,
                            'birth_date' => $reg->birth_date,
                            'religion' => $reg->religion ?? 'Islam',
                            'address' => $reg->address,
                            'rt' => $reg->rt,
                            'rw' => $reg->rw,
                            'village' => $reg->village,
                            'district' => $reg->district,
                            'regency' => $reg->regency,
                            'postal_code' => $reg->postal_code,
                            'prev_school_name' => $reg->prev_school_name,
                            'graduation_year' => $reg->graduation_year,
                            'father_name' => $reg->father_name,
                            'father_nik' => $reg->father_nik,
                            'father_job' => $reg->father_job,
                            'father_income' => $reg->father_income,
                            'father_phone' => $reg->father_phone,
                            'mother_name' => $reg->mother_name,
                            'mother_nik' => $reg->mother_nik,
                            'mother_job' => $reg->mother_job,
                            'mother_income' => $reg->mother_income,
                            'mother_phone' => $reg->mother_phone,
                            'guardian_name' => $reg->guardian_name,
                            'guardian_nik' => $reg->guardian_nik,
                            'guardian_job' => $reg->guardian_job,
                            'guardian_phone' => $reg->guardian_phone,
                            'status' => 'active',
                            'enrollment_type' => 'Baru',
                        ]);
                    }

                    self::assignStudentToRombel($student, $rombel, $academicYear);

                    $reg->update([
                        'status' => PpdbRegistration::STATUS_PLACED,
                        'converted_student_id' => $student->id,
                    ]);

                    $successCount++;
                }
            }

            // Proses siswa EMIS tanpa rombel
            if (!empty($studentIds)) {
                $students = Student::whereIn('id', $studentIds)
                    ->where('status', 'active')
                    ->get();

                foreach ($students as $student) {
                    self::assignStudentToRombel($student, $rombel, $academicYear);
                    $successCount++;
                }
            }
        });

        return back()->with('success', "Berhasil menempatkan {$successCount} siswa ke {$rombel->name}.");
    }

    /**
     * Helper: Assign siswa ke rombel via StudentAcademicCohort.
     */
    private static function assignStudentToRombel(Student $student, Rombel $rombel, AcademicYear $academicYear): void
    {
        $existingCohort = StudentAcademicCohort::where('student_id', $student->id)
            ->where('academic_year_id', $academicYear->id)
            ->first();

        if (!$existingCohort) {
            $nextAttendanceNumber = StudentAcademicCohort::where('rombel_id', $rombel->id)
                ->where('academic_year_id', $academicYear->id)
                ->max('attendance_number') ?? 0;

            StudentAcademicCohort::create([
                'student_id' => $student->id,
                'academic_year_id' => $academicYear->id,
                'semester' => $academicYear->semester,
                'rombel_id' => $rombel->id,
                'attendance_number' => $nextAttendanceNumber + 1,
                'status' => 'active',
            ]);
        } else {
            $existingCohort->update([
                'rombel_id' => $rombel->id,
                'status' => 'active',
            ]);
        }
    }
}
