<?php

namespace App\Http\Controllers;

use App\Jobs\UploadToGoogleDriveJob;
use App\Models\AcademicYear;
use App\Models\PpdbDocument;
use App\Models\PpdbPeriod;
use App\Models\PpdbRegistration;
use App\Models\Setting;
use App\Services\FileCompressionService;
use App\Services\GeminiPpdbService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class PpdbController extends Controller
{
    /**
     * Dashboard PPDB: Menampilkan ringkasan statistik, periode aktif, dan pendaftar terbaru.
     */
    public function index()
    {
        $activeYear = AcademicYear::active();
        $activePeriod = PpdbPeriod::active();

        // Periode list dengan jumlah pendaftar
        $periods = PpdbPeriod::with('academicYear')
            ->withCount('registrations')
            ->orderBy('created_at', 'desc')
            ->get();

        // Statistik pendaftar (berdasarkan periode aktif jika ada, atau seluruhnya)
        $statQuery = PpdbRegistration::query();
        if ($activePeriod) {
            $statQuery->where('ppdb_period_id', $activePeriod->id);
        }

        $stats = [
            'total' => (clone $statQuery)->count(),
            'registered' => (clone $statQuery)->where('status', PpdbRegistration::STATUS_REGISTERED)->count(),
            're_enrolled' => (clone $statQuery)->where('status', PpdbRegistration::STATUS_RE_ENROLLED)->count(),
            'verified' => (clone $statQuery)->where('status', PpdbRegistration::STATUS_VERIFIED)->count(),
            'placed' => (clone $statQuery)->where('status', PpdbRegistration::STATUS_PLACED)->count(),
        ];

        // 5 Pendaftar terbaru
        $recentRegistrations = (clone $statQuery)
            ->with('period')
            ->orderBy('created_at', 'desc')
            ->take(5)
            ->get();

        $academicYears = AcademicYear::orderBy('year', 'desc')->get();

        return Inertia::render('Ppdb/Index', [
            'activePeriod' => $activePeriod ? $activePeriod->load('academicYear') : null,
            'periods' => $periods,
            'stats' => $stats,
            'recentRegistrations' => $recentRegistrations,
            'academicYears' => $academicYears,
        ]);
    }

    /**
     * Simpan periode PPDB baru.
     */
    public function storePeriod(Request $request)
    {
        $request->validate([
            'academic_year_id' => 'required|exists:academic_years,id',
            'name' => 'required|string|max:255',
            'open_date' => 'required|date',
            'close_date' => 'required|date|after_or_equal:open_date',
            'is_active' => 'nullable|boolean',
            'description' => 'nullable|string',
        ]);

        $isActive = $request->boolean('is_active');

        if ($isActive) {
            PpdbPeriod::where('is_active', true)->update(['is_active' => false]);
        }

        PpdbPeriod::create([
            'academic_year_id' => $request->academic_year_id,
            'name' => $request->name,
            'open_date' => $request->open_date,
            'close_date' => $request->close_date,
            'is_active' => $isActive,
            'description' => $request->description,
        ]);

        return back()->with('success', 'Periode PPDB berhasil ditambahkan.');
    }

    /**
     * Toggle status aktif periode PPDB.
     */
    public function togglePeriodActive(PpdbPeriod $period)
    {
        if (! $period->is_active) {
            PpdbPeriod::where('is_active', true)->update(['is_active' => false]);
            $period->update(['is_active' => true]);
            $message = "Periode '{$period->name}' sekarang aktif.";
        } else {
            $period->update(['is_active' => false]);
            $message = "Periode '{$period->name}' telah dinonaktifkan.";
        }

        return back()->with('success', $message);
    }

    /**
     * Daftar seluruh pendaftar dengan filter dan pencarian.
     */
    public function registrations(Request $request)
    {
        $activePeriod = PpdbPeriod::active();
        $selectedPeriodId = $request->input('period_id', $activePeriod?->id);

        $query = PpdbRegistration::with(['period.academicYear', 'convertedStudent'])
            ->orderBy('created_at', 'desc');

        if ($selectedPeriodId && $selectedPeriodId !== 'all') {
            $query->where('ppdb_period_id', $selectedPeriodId);
        }

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('registration_number', 'like', "%{$search}%")
                    ->orWhere('nisn', 'like', "%{$search}%")
                    ->orWhere('nik', 'like', "%{$search}%")
                    ->orWhere('prev_school_name', 'like', "%{$search}%");
            });
        }

        // Summary counts for current period filter
        $countQuery = PpdbRegistration::query();
        if ($selectedPeriodId && $selectedPeriodId !== 'all') {
            $countQuery->where('ppdb_period_id', $selectedPeriodId);
        }

        $counts = [
            'all' => (clone $countQuery)->count(),
            'registered' => (clone $countQuery)->where('status', PpdbRegistration::STATUS_REGISTERED)->count(),
            're_enrolled' => (clone $countQuery)->where('status', PpdbRegistration::STATUS_RE_ENROLLED)->count(),
            'verified' => (clone $countQuery)->where('status', PpdbRegistration::STATUS_VERIFIED)->count(),
            'placed' => (clone $countQuery)->where('status', PpdbRegistration::STATUS_PLACED)->count(),
        ];

        $registrations = $query->paginate(15)->withQueryString();
        $periods = PpdbPeriod::orderBy('created_at', 'desc')->get();

        return Inertia::render('Ppdb/Registrations', [
            'registrations' => $registrations,
            'counts' => $counts,
            'periods' => $periods,
            'activePeriodId' => $selectedPeriodId,
            'filters' => $request->only(['status', 'search', 'period_id']),
        ]);
    }

    /**
     * Formulir publik PPDB (guest, tanpa login).
     * Hanya tampil bila ada periode aktif; jika tidak, tampil info penutupan.
     */
    public function publicForm()
    {
        $activePeriod = PpdbPeriod::activeForNow();

        if (! $activePeriod) {
            $periodWithIsPublic = PpdbPeriod::query()
                ->where('is_active', true)
                ->where('is_public_form_active', true)
                ->orderByDesc('open_date')
                ->first();

            // If no active period, show closed message with remaining periods info
            return Inertia::render('Ppdb/PublicForm', [
                'activePeriod' => null,
                'periods' => PpdbPeriod::orderByDesc('open_date')->get(),
                'closed' => true,
                'madrasahName' => Setting::get('madrasah_name', env('MADRASAH_NAME', 'MTs')),
                'madrasahAddress' => Setting::get('school_address', env('MADRASAH_ADDRESS', '')),
            ]);
        }

        return Inertia::render('Ppdb/PublicForm', [
            'activePeriod' => $activePeriod,
            'madrasahName' => Setting::get('madrasah_name', env('MADRASAH_NAME', 'MTs')),
            'madrasahAddress' => Setting::get('school_address', env('MADRASAH_ADDRESS', '')),
        ]);
    }

    /**
     * Simpan pendaftaran publik (guest, throttle anti-spam).
     */
    public function publicStore(Request $request)
    {
        $period = PpdbPeriod::activeForNow();
        if (! $period) {
            return redirect()->back()->withErrors(['period' => 'Pendaftaran PPDB sedang ditutup.']);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'gender' => 'required|in:L,P',
            'birth_place' => 'required|string|max:255',
            'birth_date' => 'required|date|before:today',
            'nisn' => 'nullable|string|max:20',
            'nik' => 'nullable|string|max:20',
            'phone_number' => 'required|string|max:30',
            'address' => 'required|string',
            'village' => 'nullable|string|max:100',
            'district' => 'nullable|string|max:100',
            'regency' => 'nullable|string|max:100',
            'prev_school_name' => 'required|string|max:255',
            'father_name' => 'nullable|string|max:255',
            'father_phone' => 'nullable|string|max:30',
            'mother_name' => 'required|string|max:255',
            'mother_phone' => 'nullable|string|max:30',
        ]);

        // Tolak NISN ganda di periode yang sama
        if (! empty($validated['nisn'])) {
            $exists = PpdbRegistration::where('ppdb_period_id', $period->id)
                ->where('nisn', $validated['nisn'])
                ->exists();
            if ($exists) {
                return redirect()->back()->withErrors(['nisn' => 'NISN ini sudah terdaftar di periode ini.']);
            }
        }

        $registration = PpdbRegistration::create([
            ...$validated,
            'ppdb_period_id' => $period->id,
            'academic_year_id' => $period->academic_year_id ?? AcademicYear::active()?->id,
            'status' => PpdbRegistration::STATUS_REGISTERED,
            'registered_by' => null,
        ]);

        return Inertia::render('Ppdb/PublicSuccess', [
            'registrationNumber' => $registration->registration_number,
            'name' => $registration->name,
            'madrasahName' => Setting::get('madrasah_name', env('MADRASAH_NAME', 'MTs')),
        ]);
    }

    /**
     * Form input manual pendaftar oleh staff.
     */
    public function create()
    {
        $activePeriod = PpdbPeriod::active();
        $periods = PpdbPeriod::orderBy('created_at', 'desc')->get();

        return Inertia::render('Ppdb/Create', [
            'activePeriod' => $activePeriod,
            'periods' => $periods,
        ]);
    }

    /**
     * Simpan data pendaftar baru oleh staff.
     */
    public function storeRegistration(Request $request)
    {
        $validated = $request->validate([
            'ppdb_period_id' => 'nullable|exists:ppdb_periods,id',
            'name' => 'required|string|max:255',
            'gender' => 'required|in:L,P',
            'birth_place' => 'required|string|max:255',
            'birth_date' => 'required|date',
            'religion' => 'nullable|string|max:50',
            'nisn' => 'nullable|string|max:20',
            'nik' => 'nullable|string|max:20',
            'phone_number' => 'nullable|string|max:30',

            // Alamat
            'address' => 'nullable|string',
            'rt' => 'nullable|string|max:10',
            'rw' => 'nullable|string|max:10',
            'village' => 'nullable|string|max:100',
            'district' => 'nullable|string|max:100',
            'regency' => 'nullable|string|max:100',
            'postal_code' => 'nullable|string|max:10',

            // Asal Sekolah
            'prev_school_name' => 'nullable|string|max:255',
            'prev_school_npsn' => 'nullable|string|max:20',
            'graduation_year' => 'nullable|string|max:10',

            // Orang Tua
            'father_name' => 'nullable|string|max:255',
            'father_nik' => 'nullable|string|max:20',
            'father_job' => 'nullable|string|max:100',
            'father_income' => 'nullable|numeric',
            'father_phone' => 'nullable|string|max:30',

            'mother_name' => 'nullable|string|max:255',
            'mother_nik' => 'nullable|string|max:20',
            'mother_job' => 'nullable|string|max:100',
            'mother_income' => 'nullable|numeric',
            'mother_phone' => 'nullable|string|max:30',

            'guardian_name' => 'nullable|string|max:255',
            'guardian_nik' => 'nullable|string|max:20',
            'guardian_job' => 'nullable|string|max:100',
            'guardian_phone' => 'nullable|string|max:30',

            'notes' => 'nullable|string',
        ]);

        $period = null;
        if (! empty($validated['ppdb_period_id'])) {
            $period = PpdbPeriod::find($validated['ppdb_period_id']);
        } else {
            $period = PpdbPeriod::active();
            $validated['ppdb_period_id'] = $period?->id;
        }

        $validated['academic_year_id'] = $period?->academic_year_id ?? AcademicYear::active()?->id;
        $validated['status'] = PpdbRegistration::STATUS_REGISTERED;
        $validated['name'] = mb_strtoupper($validated['name'], 'UTF-8');
        $validated['registered_by'] = auth()->id();

        $registration = PpdbRegistration::create($validated);

        return redirect()->route('ppdb.show', $registration->id)
            ->with('success', "Pendaftar baru '{$registration->name}' berhasil didaftarkan dengan nomor {$registration->registration_number}.");
    }

    /**
     * Detail pendaftar, riwayat status, dan berkas.
     */
    public function show(PpdbRegistration $registration)
    {
        $registration->load([
            'period.academicYear',
            'documents',
            'convertedStudent.cohorts.rombel',
        ]);

        return Inertia::render('Ppdb/Show', [
            'registration' => $registration,
        ]);
    }

    /**
     * Form edit data pendaftar.
     */
    public function edit(PpdbRegistration $registration)
    {
        $periods = PpdbPeriod::orderBy('created_at', 'desc')->get();

        return Inertia::render('Ppdb/Edit', [
            'registration' => $registration,
            'periods' => $periods,
        ]);
    }

    /**
     * Update data pendaftar.
     */
    public function updateRegistration(Request $request, PpdbRegistration $registration)
    {
        $validated = $request->validate([
            'ppdb_period_id' => 'nullable|exists:ppdb_periods,id',
            'name' => 'required|string|max:255',
            'gender' => 'required|in:L,P',
            'birth_place' => 'required|string|max:255',
            'birth_date' => 'required|date',
            'religion' => 'nullable|string|max:50',
            'nisn' => 'nullable|string|max:20',
            'nik' => 'nullable|string|max:20',
            'phone_number' => 'nullable|string|max:30',

            // Alamat
            'address' => 'nullable|string',
            'rt' => 'nullable|string|max:10',
            'rw' => 'nullable|string|max:10',
            'village' => 'nullable|string|max:100',
            'district' => 'nullable|string|max:100',
            'regency' => 'nullable|string|max:100',
            'postal_code' => 'nullable|string|max:10',

            // Asal Sekolah
            'prev_school_name' => 'nullable|string|max:255',
            'prev_school_npsn' => 'nullable|string|max:20',
            'graduation_year' => 'nullable|string|max:10',

            // Orang Tua
            'father_name' => 'nullable|string|max:255',
            'father_nik' => 'nullable|string|max:20',
            'father_job' => 'nullable|string|max:100',
            'father_income' => 'nullable|numeric',
            'father_phone' => 'nullable|string|max:30',

            'mother_name' => 'nullable|string|max:255',
            'mother_nik' => 'nullable|string|max:20',
            'mother_job' => 'nullable|string|max:100',
            'mother_income' => 'nullable|numeric',
            'mother_phone' => 'nullable|string|max:30',

            'guardian_name' => 'nullable|string|max:255',
            'guardian_nik' => 'nullable|string|max:20',
            'guardian_job' => 'nullable|string|max:100',
            'guardian_phone' => 'nullable|string|max:30',

            'notes' => 'nullable|string',
        ]);

        $validated['name'] = mb_strtoupper($validated['name'], 'UTF-8');
        $registration->update($validated);

        return redirect()->route('ppdb.show', $registration->id)
            ->with('success', 'Data pendaftar berhasil diperbarui.');
    }

    /**
     * Hapus pendaftar.
     */
    public function destroyRegistration(PpdbRegistration $registration)
    {
        if ($registration->status === PpdbRegistration::STATUS_PLACED) {
            return back()->with('error', 'Pendaftar yang sudah ditempatkan ke rombel tidak dapat dihapus langsung.');
        }

        $registration->delete();

        return redirect()->route('ppdb.registrations')
            ->with('success', "Pendaftar '{$registration->name}' berhasil dihapus.");
    }

    /**
     * Ubah status pendaftar (registered -> re_enrolled -> verified -> placed).
     */
    public function updateStatus(Request $request, PpdbRegistration $registration)
    {
        $request->validate([
            'status' => 'required|in:registered,re_enrolled,verified,placed',
        ]);

        $newStatus = $request->status;

        if ($newStatus === PpdbRegistration::STATUS_PLACED && empty($registration->converted_student_id)) {
            return back()->with('error', 'Untuk mengubah status menjadi Placed, silakan lakukan melalui menu Penempatan Rombel.');
        }

        $registration->update(['status' => $newStatus]);

        $statusLabels = [
            'registered' => 'Registered',
            're_enrolled' => 'Daftar Ulang',
            'verified' => 'Berkas Terverifikasi',
            'placed' => 'Ditempatkan di Rombel',
        ];

        return back()->with('success', "Status pendaftar diubah menjadi {$statusLabels[$newStatus]}.");
    }

    /**
     * Pindai dokumen form PPDB dengan AI dan simpan.
     */
    public function scanDocument(Request $request, PpdbRegistration $registration, GeminiPpdbService $aiService, FileCompressionService $compressionService)
    {
        $request->validate([
            'document' => 'required|file|mimes:pdf,jpg,jpeg,png|max:5120',
        ]);

        $file = $request->file('document');
        $filePath = $file->getPathname();
        $mimeType = $file->getMimeType();
        $originalName = $file->getClientOriginalName();
        $extension = $file->getClientOriginalExtension();

        // 1. Kompres file (jika ukuran melebihi 1MB)
        $compressedPath = $compressionService->compressIfNeeded($filePath, $mimeType, $extension);
        $fileSize = filesize($compressedPath);

        // 2. Scan using Gemini (secara sinkron)
        $extractedData = $aiService->parseDocument($compressedPath, $mimeType);
        if (! $extractedData) {
            return back()->with('error', 'Gagal memindai dokumen menggunakan AI. Pastikan dokumen dapat terbaca dengan jelas.');
        }

        // 3. Upload to Local Private Storage
        $newFilename = $registration->registration_number.'_'.time().'.'.$extension;
        $archivePath = 'archives/ppdb/'.$newFilename;
        Storage::disk('local')->put($archivePath, file_get_contents($compressedPath));

        // 4. Save to ppdb_documents
        $document = PpdbDocument::create([
            'ppdb_registration_id' => $registration->id,
            'document_type' => 'formulir_pendaftaran',
            'document_name' => $originalName,
            'file_path' => $archivePath,
            'file_size' => $fileSize,
            'mime_type' => $mimeType,
            'ai_extracted_data' => $extractedData,
            'is_verified' => true,
            'verified_at' => now(),
            'status' => 'pending', // Untuk GDrive
            'upload_retries' => 0,
        ]);

        // 5. Dispatch background job untuk upload ke GDrive
        UploadToGoogleDriveJob::dispatch('ppdb', $document->id);

        // 5. Update Registration Data
        $updateData = [];
        $fillable = [
            'name', 'nisn', 'nik', 'gender', 'birth_place', 'birth_date', 'religion',
            'address', 'rt', 'rw', 'village', 'district', 'regency', 'postal_code',
            'prev_school_name', 'father_name', 'father_nik', 'father_job', 'father_income',
            'mother_name', 'mother_nik', 'mother_job', 'mother_income',
            'guardian_name', 'guardian_nik', 'guardian_job',
        ];

        foreach ($fillable as $field) {
            if (isset($extractedData[$field])) {
                $updateData[$field] = $extractedData[$field];
            }
        }

        if (isset($updateData['name'])) {
            $updateData['name'] = mb_strtoupper($updateData['name'], 'UTF-8');
        }

        $updateData['status'] = PpdbRegistration::STATUS_VERIFIED;

        $registration->update($updateData);

        return back()->with('success', 'Dokumen berhasil dipindai dan biodata telah diperbarui secara otomatis.');
    }
}
