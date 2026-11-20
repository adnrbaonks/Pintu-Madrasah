<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\Setting;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SettingController extends Controller
{
    public function index()
    {
        $settings = [
            'madrasah_name' => Setting::get('madrasah_name', env('MADRASAH_NAME', 'MTs Negeri Contoh')),
            'nsm' => Setting::get('nsm', ''),
            'npsn' => Setting::get('npsn', ''),
            'headmaster_name' => Setting::get('headmaster_name', ''),
            'headmaster_nip' => Setting::get('headmaster_nip', ''),
            'school_address' => Setting::get('school_address', ''),
            'madrasah_photo' => Setting::get('madrasah_photo', null),
            'gemini_api_key' => Setting::get('gemini_api_key', env('GEMINI_API_KEY', '')),
            'api_key' => Setting::get('api_key', env('API_GATEWAY_KEY', '')),
        ];

        $academicYears = AcademicYear::orderBy('year', 'desc')->orderBy('semester', 'desc')->get();

        return Inertia::render('Settings/Index', [
            'settings' => $settings,
            'academicYears' => $academicYears,
        ]);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'madrasah_name' => 'required|string|max:255',
            'nsm' => 'nullable|string|max:50',
            'npsn' => 'nullable|string|max:50',
            'headmaster_name' => 'nullable|string|max:255',
            'headmaster_nip' => 'nullable|string|max:50',
            'school_address' => 'nullable|string',
            'madrasah_photo' => 'nullable|image|max:5120',
            'gemini_api_key' => 'nullable|string|max:255',
            'api_key' => 'nullable|string|max:255',
        ]);

        if ($request->hasFile('madrasah_photo')) {
            $path = $request->file('madrasah_photo')->store('settings', 'public');
            Setting::set('madrasah_photo', '/storage/'.$path);
            unset($validated['madrasah_photo']);
        }

        foreach ($validated as $key => $value) {
            Setting::set($key, $value);
        }

        return redirect()->back()->with('success', 'Pengaturan Profil Madrasah berhasil disimpan.');
    }

    public function storeAcademicYear(Request $request)
    {
        $validated = $request->validate([
            'year' => 'required|string|regex:/^\d{4}\/\d{4}$/', // e.g., 2026/2027
            'semester' => 'required|in:Ganjil,Genap',
        ]);

        AcademicYear::create([
            'year' => $validated['year'],
            'semester' => $validated['semester'],
            'is_active' => false,
            'is_locked' => false,
        ]);

        return redirect()->back()->with('success', 'Tahun Ajaran baru berhasil ditambahkan.');
    }

    public function setActiveAcademicYear(Request $request, AcademicYear $academicYear)
    {
        AcademicYear::where('is_active', true)->update(['is_active' => false]);
        $academicYear->update(['is_active' => true]);

        return redirect()->back()->with('success', 'Tahun Ajaran aktif berhasil diubah.');
    }

    public function toggleLockAcademicYear(Request $request, AcademicYear $academicYear)
    {
        $academicYear->update(['is_locked' => ! $academicYear->is_locked]);

        $status = $academicYear->is_locked ? 'dikunci' : 'dibuka kuncinya';

        return redirect()->back()->with('success', "Tahun Ajaran berhasil $status.");
    }

    public function saveEmisSsoToken(Request $request)
    {
        $request->validate([
            'emis_sso_token' => 'required|string|min:20',
        ]);

        Setting::set('emis_sso_token', $request->emis_sso_token);

        return response()->json(['success' => true, 'message' => 'Token emisSSO berhasil disimpan.']);
    }
}
