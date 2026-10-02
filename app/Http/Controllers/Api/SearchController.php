<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Letter;
use App\Models\Rombel;
use App\Models\Staff;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Global search API for Command Palette (Ctrl+K).
 */
class SearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $q = trim($request->get('q', ''));

        if (strlen($q) < 2) {
            return response()->json(['results' => []]);
        }

        $results = [];

        // ── Siswa ──────────────────────────────────────────────────────
        Student::where('status', 'active')
            ->where(fn ($query) =>
                $query->where('name', 'like', "%{$q}%")
                      ->orWhere('nisn', 'like', "%{$q}%")
                      ->orWhere('nik', 'like', "%{$q}%")
                      ->orWhere('nis_local', 'like', "%{$q}%")
            )
            ->select('id', 'name', 'nisn', 'status')
            ->limit(6)
            ->get()
            ->each(fn ($s) => $results[] = [
                'type'     => 'siswa',
                'label'    => $s->name,
                'subtitle' => 'NISN: ' . ($s->nisn ?? '—'),
                'href'     => "/students/{$s->id}",
                'icon'     => 'student',
            ]);

        // ── GTK ────────────────────────────────────────────────────────
        Staff::where('is_active', true)
            ->where(fn ($query) =>
                $query->where('full_name_with_titles', 'like', "%{$q}%")
                      ->orWhere('nip', 'like', "%{$q}%")
                      ->orWhere('nuptk', 'like', "%{$q}%")
                      ->orWhere('nik', 'like', "%{$q}%")
            )
            ->select('id', 'full_name_with_titles', 'nip', 'employment_status')
            ->limit(4)
            ->get()
            ->each(fn ($g) => $results[] = [
                'type'     => 'gtk',
                'label'    => $g->full_name_with_titles,
                'subtitle' => strtoupper($g->employment_status ?? 'GTK') . ($g->nip ? ' | NIP: ' . $g->nip : ''),
                'href'     => "/gtk/{$g->id}",
                'icon'     => 'gtk',
            ]);

        // ── Surat ──────────────────────────────────────────────────────
        Letter::where(fn ($query) =>
                $query->where('subject', 'like', "%{$q}%")
                      ->orWhere('number', 'like', "%{$q}%")
                      ->orWhere('from_to', 'like', "%{$q}%")
            )
            ->select('id', 'type', 'number', 'subject', 'letter_date')
            ->limit(3)
            ->get()
            ->each(fn ($l) => $results[] = [
                'type'     => 'surat',
                'label'    => $l->subject,
                'subtitle' => 'Surat ' . ucfirst($l->type) . ' | ' . $l->number,
                'href'     => "/persuratan/{$l->id}",
                'icon'     => 'letter',
            ]);

        // ── Menu / Halaman (Static) ─────────────────────────────────────
        $menuItems = [
            ['label' => 'Dashboard', 'href' => '/', 'keywords' => 'dashboard beranda home'],
            ['label' => 'Master Data Siswa', 'href' => '/students', 'keywords' => 'siswa murid data'],
            ['label' => 'PPDB - Pendaftaran Baru', 'href' => '/ppdb/registrations', 'keywords' => 'ppdb pendaftaran daftar baru'],
            ['label' => 'Penempatan Rombel', 'href' => '/rombel', 'keywords' => 'rombel kelas penempatan'],
            ['label' => 'Master Data GTK', 'href' => '/gtk', 'keywords' => 'gtk guru tenaga kependidikan'],
            ['label' => 'Sync EMIS GTK', 'href' => '/emis/gtk', 'keywords' => 'emis gtk sync sinkronisasi'],
            ['label' => 'Sync EMIS Siswa', 'href' => '/emis/siswa', 'keywords' => 'emis siswa sync'],
            ['label' => 'Leger Nilai', 'href' => '/kelas/leger', 'keywords' => 'leger nilai rapor rekap'],
            ['label' => 'Persuratan', 'href' => '/persuratan', 'keywords' => 'surat persuratan agenda'],
            ['label' => 'Statistik Demografi', 'href' => '/statistik/demografi', 'keywords' => 'statistik demografi data'],
            ['label' => 'Validasi TPG', 'href' => '/gtk/tpg', 'keywords' => 'tpg sertifikasi tunjangan'],
            ['label' => 'Kenaikan Kelas', 'href' => '/kenaikan-kelas', 'keywords' => 'promosi naik kelas'],
            ['label' => 'Mutasi Siswa', 'href' => '/mutasi', 'keywords' => 'mutasi pindah siswa'],
            ['label' => 'Arsip Siswa', 'href' => '/arsip', 'keywords' => 'arsip alumni lulus'],
            ['label' => 'Cetak Biodata', 'href' => '/cetak-format', 'keywords' => 'cetak print biodata'],
            ['label' => 'Manajemen Pengguna', 'href' => '/users', 'keywords' => 'users pengguna admin'],
            ['label' => 'Pengaturan Madrasah', 'href' => '/pengaturan', 'keywords' => 'pengaturan setting konfigurasi'],
            ['label' => 'Profil Saya', 'href' => '/profile', 'keywords' => 'profil akun password'],
            ['label' => 'Audit Log', 'href' => '/audit-logs', 'keywords' => 'audit log aktivitas'],
        ];

        $qLower = strtolower($q);
        foreach ($menuItems as $item) {
            if (str_contains(strtolower($item['label']), $qLower) || str_contains($item['keywords'], $qLower)) {
                $results[] = [
                    'type'     => 'menu',
                    'label'    => $item['label'],
                    'subtitle' => $item['href'],
                    'href'     => $item['href'],
                    'icon'     => 'menu',
                ];
            }
        }

        return response()->json(['results' => array_slice($results, 0, 15)]);
    }
}
