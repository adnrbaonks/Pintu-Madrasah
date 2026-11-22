<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleController extends Controller
{
    /**
     * Label dan deskripsi resmi untuk ke-7 role madrasah.
     *
     * @return array<string, array{label: string, description: string}>
     */
    private function roleMeta(): array
    {
        return [
            'admin' => [
                'label' => 'Administrator',
                'description' => 'Akses penuh ke seluruh modul, pengaturan sistem, API, dan manajemen pengguna.',
            ],
            'kepala_madrasah' => [
                'label' => 'Kepala Madrasah',
                'description' => 'Supervisi eksekutif, pemantauan akademik/kepegawaian, validasi TPG, dan log audit.',
            ],
            'kaur_tu' => [
                'label' => 'Kepala Tata Usaha (Kaur TU)',
                'description' => 'Supervisi ketatausahaan, pengelolaan GTK/siswa, mutasi, persuratan, dan validasi.',
            ],
            'staf' => [
                'label' => 'Staf Tata Usaha',
                'description' => 'Operasional administrasi harian, persuratan, mutasi siswa, rombel, dan dokumen GTK.',
            ],
            'operator' => [
                'label' => 'Operator Madrasah',
                'description' => 'Pengelolaan teknis data induk, sinkronisasi EMIS, PPDB, NISM, dan leger.',
            ],
            'guru' => [
                'label' => 'Guru',
                'description' => 'Pembelajaran, input leger nilai, pembinaan BK, dan cetak kartu/dokumen akademik.',
            ],
            'pegawai' => [
                'label' => 'Pegawai',
                'description' => 'Tenaga kependidikan non-guru dengan akses informasi akademik dasar dan cetak dokumen.',
            ],
        ];
    }

    /**
     * Pengelompokan permission per domain modul agar mudah dibaca di UI.
     *
     * @return array<string, array{label: string, permissions: array<string, string>}>
     */
    private function permissionCatalog(): array
    {
        return [
            'students' => [
                'label' => 'Kesiswaan & Data Induk Siswa',
                'permissions' => [
                    'view_students' => 'Lihat Daftar & Profil Siswa',
                    'create_students' => 'Tambah Siswa Baru',
                    'edit_students' => 'Ubah Biodata Siswa',
                    'delete_students' => 'Hapus Data Siswa',
                    'export_students' => 'Ekspor Data Siswa (Excel/PDF)',
                    'manage_mutations' => 'Kelola Mutasi Masuk/Keluar',
                    'manage_placements' => 'Kelola Penempatan Rombel',
                    'manage_promotions' => 'Kelola Kenaikan Kelas & Kelulusan',
                    'manage_bk' => 'Kelola Catatan BK & Kedisiplinan',
                ],
            ],
            'staff' => [
                'label' => 'Kepegawaian (GTK)',
                'permissions' => [
                    'view_staff' => 'Lihat Daftar & Profil GTK',
                    'create_staff' => 'Tambah Data GTK',
                    'edit_staff' => 'Ubah Profil & Arsip Dokumen GTK',
                    'delete_staff' => 'Hapus Data GTK',
                    'validate_tpg' => 'Validasi Kelayakan TPG',
                ],
            ],
            'academic' => [
                'label' => 'Akademik & Master Data',
                'permissions' => [
                    'view_rombels' => 'Lihat Rombongan Belajar',
                    'manage_rombels' => 'Kelola Rombel & Wali Kelas',
                    'view_academic_years' => 'Lihat Tahun Ajaran',
                    'manage_academic_years' => 'Kelola Tahun Ajaran, Kelas, & Mapel',
                    'view_grades' => 'Lihat Leger Nilai',
                    'manage_grades' => 'Input & Ubah Nilai / Formula Leger',
                    'view_exam_cards' => 'Cetak Kartu Ujian & Denah Kursi',
                ],
            ],
            'ppdb_letters' => [
                'label' => 'PPDB & Persuratan',
                'permissions' => [
                    'view_ppdb' => 'Lihat Data PPDB',
                    'manage_ppdb' => 'Kelola Periode & Seleksi PPDB',
                    'view_letters' => 'Lihat Arsip Persuratan',
                    'manage_letters' => 'Buat & Kelola Surat',
                    'print_documents' => 'Cetak Format Administrasi Kelas',
                ],
            ],
            'integration_system' => [
                'label' => 'Integrasi & Sistem',
                'permissions' => [
                    'view_emis' => 'Lihat Staging Sinkronisasi EMIS',
                    'sync_emis' => 'Jalankan Tarik & Commit Data EMIS',
                    'manage_api_clients' => 'Kelola API Key Satelit',
                    'manage_webhooks' => 'Kelola Webhook Subscription',
                    'view_audit_logs' => 'Lihat Log Audit Aktivitas',
                    'manage_users' => 'Kelola Akun Pengguna & Role',
                    'manage_settings' => 'Kelola Pengaturan Madrasah',
                ],
            ],
        ];
    }

    public function index(): Response
    {
        $meta = $this->roleMeta();
        $roles = Role::with('permissions')->get()->keyBy('name');

        $formattedRoles = [];
        foreach ($meta as $roleName => $info) {
            $roleModel = $roles->get($roleName);
            $formattedRoles[] = [
                'id' => $roleModel?->id,
                'name' => $roleName,
                'label' => $info['label'],
                'description' => $info['description'],
                'is_locked' => $roleName === 'admin',
                'users_count' => User::where('role', $roleName)->count(),
                'permissions' => $roleModel
                    ? $roleModel->permissions->pluck('name')->values()->all()
                    : [],
            ];
        }

        return Inertia::render('Settings/Roles/Index', [
            'roles' => $formattedRoles,
            'catalog' => $this->permissionCatalog(),
        ]);
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        if ($role->name === 'admin') {
            abort(422, 'Hak akses role Administrator bersifat penuh dan tidak dapat dikurangi.');
        }

        $allKnownPermissions = Permission::pluck('name')->all();

        $validated = $request->validate([
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::in($allKnownPermissions)],
        ]);

        $beforePermissions = $role->permissions()->pluck('name')->sort()->values()->all();
        $newPermissions = collect($validated['permissions'])->unique()->sort()->values()->all();

        $role->syncPermissions($newPermissions);
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'updated_role_permissions',
            'model_type' => Role::class,
            'model_id' => null,
            'before_payload' => [
                'role' => $role->name,
                'permissions' => $beforePermissions,
            ],
            'after_payload' => [
                'role' => $role->name,
                'permissions' => $newPermissions,
            ],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return redirect()->route('settings.roles.index')
            ->with('success', "Matriks izin untuk role {$role->name} berhasil diperbarui.");
    }
}
