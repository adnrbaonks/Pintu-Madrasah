<?php

namespace App\Http\Controllers;

use App\Jobs\PullEmisMutationDataJob;
use App\Models\StudentMutation;
use App\Models\Student;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;

class MutationController extends Controller
{
    public function index(Request $request)
    {
        $query = StudentMutation::query();

        if ($request->has('type') && $request->type !== 'all') {
            $query->where('type', $request->type);
        }

        if ($request->has('search') && $request->search !== '') {
            $query->where(function($q) use ($request) {
                $q->where('name', 'like', '%' . $request->search . '%')
                  ->orWhere('nisn', 'like', '%' . $request->search . '%');
            });
        }

        $mutations = $query->with('student:id,name,nisn')->orderBy('mutation_date', 'desc')->paginate(10)->withQueryString();
        
        $activeStudents = Student::where('status', 'active')->select('id', 'name', 'nisn')->orderBy('name')->get();

        return Inertia::render('Students/Mutations/Index', [
            'mutations' => $mutations,
            'students' => $activeStudents,
            'filters' => $request->only(['type', 'search']),
        ]);
    }

    public function sync()
    {
        PullEmisMutationDataJob::dispatch()->onQueue('emis');
        return back()->with('success', 'Penarikan data mutasi dari EMIS sedang berjalan di latar belakang.');
    }

    public function progress()
    {
        $progress = Cache::get('emis:mutations:progress');
        
        if (!$progress) {
            return response()->json(['status' => 'Belum ada proses sinkronisasi mutasi yang berjalan.', 'progress' => 0]);
        }

        return response()->json($progress);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'type' => 'required|in:in,out',
            'mutation_date' => 'required|date',
            'reason' => 'nullable|string|max:255',
        ]);

        DB::beginTransaction();
        try {
            if ($validated['type'] === 'in') {
                $req = $request->validate([
                    'name' => 'required|string|max:255',
                    'nisn' => 'nullable|string|max:20',
                    'gender' => 'required|in:L,P',
                    'origin_school' => 'required|string|max:255',
                ]);
                
                // Create a pending student profile
                $student = Student::create([
                    'name' => $req['name'],
                    'nisn' => $req['nisn'],
                    'gender' => $req['gender'],
                    'birth_place' => '-', // Dummy value, TU akan update nanti
                    'birth_date' => '2000-01-01', // Dummy value, TU akan update nanti
                    'status' => 'pending', // Special status for incoming mutation before document is uploaded
                ]);

                StudentMutation::create([
                    'student_id' => $student->id,
                    'name' => $req['name'],
                    'nisn' => $req['nisn'],
                    'type' => 'in',
                    'mutation_date' => $validated['mutation_date'],
                    'reason' => $validated['reason'],
                    'origin_school' => $req['origin_school'],
                    'status' => 'pending',
                ]);
            } else {
                $req = $request->validate([
                    'student_id' => 'required|exists:students,id',
                    'destination_school' => 'required|string|max:255',
                ]);

                $student = Student::findOrFail($req['student_id']);

                StudentMutation::create([
                    'student_id' => $student->id,
                    'name' => $student->name,
                    'nisn' => $student->nisn,
                    'type' => 'out',
                    'mutation_date' => $validated['mutation_date'],
                    'reason' => $validated['reason'],
                    'destination_school' => $req['destination_school'],
                    'status' => 'pending',
                ]);
            }
            DB::commit();
            return back()->with('success', 'Mutasi berhasil diregister dan menunggu dokumen.');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withErrors(['error' => 'Gagal membuat registrasi mutasi: ' . $e->getMessage()]);
        }
    }

    public function uploadDocument(Request $request, StudentMutation $mutation, \App\Services\FileCompressionService $compressionService)
    {
        $request->validate([
            'document' => 'required|file|mimes:pdf,jpg,jpeg,png|max:5120', // max 5MB, will compress
        ]);

        if ($mutation->status === 'completed') {
            return back()->withErrors(['error' => 'Mutasi ini sudah diselesaikan.']);
        }

        $file = $request->file('document');
        $filePath = $file->getPathname();
        $mimeType = $file->getMimeType();
        $extension = $file->getClientOriginalExtension();

        // 1. Kompres file (jika ukuran melebihi 1MB)
        $compressedPath = $compressionService->compressIfNeeded($filePath, $mimeType, $extension);
        
        // 2. Simpan di folder privat lokal
        $newFilename = $mutation->id . '_' . time() . '.' . $extension;
        $archivePath = 'archives/mutation/' . $newFilename;
        \Illuminate\Support\Facades\Storage::disk('local')->put($archivePath, file_get_contents($compressedPath));

        DB::beginTransaction();
        try {
            $mutation->update([
                'document_path' => $archivePath,
                'status' => 'completed',
                'document_status' => 'pending', // Menunggu GDrive upload
                'gdrive_file_id' => null,
                'gdrive_view_url' => null,
                'upload_retries' => 0,
            ]);

            $student = $mutation->student;
            
            if ($student) {
                if ($mutation->type === 'in') {
                    $student->update(['status' => 'active']);
                } else if ($mutation->type === 'out') {
                    $student->update(['status' => 'mutated_out']);
                    // Nonaktifkan cohort saat ini jika ada
                    $currentCohort = $student->cohorts()->where('is_active', true)->first();
                    if ($currentCohort) {
                        $currentCohort->update(['is_active' => false]);
                    }
                }
            }

            DB::commit();

            // 3. Dispatch background job untuk upload ke GDrive
            \App\Jobs\UploadToGoogleDriveJob::dispatch('mutation', $mutation->id);

            return back()->with('success', 'Dokumen berhasil diunggah, mutasi selesai, dan file sedang diproses ke Google Drive.');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withErrors(['error' => 'Gagal menyelesaikan mutasi: ' . $e->getMessage()]);
        }
    }

    public function update(Request $request, StudentMutation $mutation)
    {
        $validated = $request->validate([
            'mutation_date' => 'required|date',
            'reason' => 'nullable|string|max:255',
            'origin_school' => 'nullable|string|max:255',
            'destination_school' => 'nullable|string|max:255',
        ]);

        $mutation->update($validated);

        return back()->with('success', 'Data mutasi berhasil diperbarui.');
    }

    public function destroy(StudentMutation $mutation)
    {
        if ($mutation->status === 'completed') {
            return back()->withErrors(['error' => 'Tidak dapat menghapus mutasi yang sudah diselesaikan.']);
        }

        DB::beginTransaction();
        try {
            // Jika Mutasi Masuk dan status masih pending, hapus profil siswa pendingnya juga
            if ($mutation->type === 'in' && $mutation->status === 'pending') {
                $student = $mutation->student;
                if ($student && $student->status === 'pending') {
                    $student->delete();
                }
            }

            $mutation->delete();
            DB::commit();

            return back()->with('success', 'Data mutasi berhasil dihapus.');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withErrors(['error' => 'Gagal menghapus data mutasi: ' . $e->getMessage()]);
        }
    }
}
