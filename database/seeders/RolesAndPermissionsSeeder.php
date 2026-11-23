<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $registrar = app()[PermissionRegistrar::class];

        // Reset cache permission
        $registrar->forgetCachedPermissions();

        // ── 1. Katalog Permission Lengkap ─────────────────────────────
        $permissions = [
            // Siswa
            'view_students', 'create_students', 'edit_students', 'delete_students', 'export_students',
            // GTK
            'view_staff', 'create_staff', 'edit_staff', 'delete_staff', 'validate_tpg',
            'verify_staff_changes', 'approve_staff_changes',
            'manage_archives', 'manage_organization',
            // Akademik & Rombel
            'view_rombels', 'manage_rombels',
            'view_academic_years', 'manage_academic_years',
            'view_grades', 'manage_grades',
            'view_exam_cards',
            // Kesiswaan (Mutasi, Penempatan, Kenaikan Kelas)
            'manage_mutations',
            'manage_placements',
            'manage_promotions',
            'manage_bk',
            // PPDB
            'view_ppdb', 'manage_ppdb',
            // Persuratan & Cetak
            'view_letters', 'manage_letters',
            'print_documents',
            // Integrasi & EMIS
            'view_emis', 'sync_emis',
            'manage_api_clients', 'manage_webhooks',
            // Sistem
            'view_audit_logs', 'manage_users', 'manage_settings',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission);
        }

        $registrar->forgetCachedPermissions();

        // ── 2. Definisi Role Resmi Madrasah ────────────────────────────

        // 1) Guru: Akses nilai, rombel, BK, cetak kartu, profil siswa
        $guru = Role::findOrCreate('guru');
        $guru->syncPermissions([
            'view_students',
            'view_rombels',
            'view_academic_years',
            'view_grades', 'manage_grades',
            'view_exam_cards',
            'manage_bk',
            'print_documents',
        ]);

        // 2) Pegawai: Staf non-guru umum (akses profil diri & cetak)
        $pegawai = Role::findOrCreate('pegawai');
        $pegawai->syncPermissions([
            'view_academic_years',
            'print_documents',
        ]);

        // 3) Staf (Staf Tata Usaha): Administrasi umum, persuratan, mutasi, master data
        $staf = Role::findOrCreate('staf');
        $staf->syncPermissions([
            'view_students', 'create_students', 'edit_students', 'export_students',
            'manage_archives', 'manage_organization',
            'view_staff', 'edit_staff',
            'view_rombels', 'manage_rombels',
            'view_academic_years',
            'view_grades',
            'view_exam_cards',
            'manage_mutations', 'manage_placements', 'manage_promotions',
            'view_ppdb',
            'view_letters', 'manage_letters',
            'print_documents',
            'view_emis',
        ]);

        // 4) Operator: Pengelola data teknis, EMIS, PPDB, sinkronisasi
        $operator = Role::findOrCreate('operator');
        $operator->syncPermissions([
            'view_students', 'create_students', 'edit_students', 'export_students',
            'manage_archives', 'manage_organization',
            'view_staff', 'create_staff', 'edit_staff',
            'view_rombels', 'manage_rombels',
            'view_academic_years', 'manage_academic_years',
            'view_grades', 'manage_grades',
            'view_exam_cards',
            'manage_mutations', 'manage_placements', 'manage_promotions',
            'manage_bk',
            'view_ppdb', 'manage_ppdb',
            'view_letters',
            'print_documents',
            'view_emis', 'sync_emis',
            'validate_tpg',
        ]);

        // 5) Kaur TU (Kepala Tata Usaha): Supervisi TU, persuratan, mutasi, verifikasi
        $kaurTu = Role::findOrCreate('kaur_tu');
        $kaurTu->syncPermissions([
            'view_students', 'create_students', 'edit_students', 'export_students',
            'manage_archives', 'manage_organization',
            'view_staff', 'edit_staff', 'verify_staff_changes',
            'view_rombels', 'manage_rombels',
            'view_academic_years',
            'view_grades',
            'manage_mutations', 'manage_placements', 'manage_promotions',
            'manage_bk',
            'view_ppdb',
            'view_letters', 'manage_letters',
            'print_documents',
            'view_emis',
            'validate_tpg',
        ]);

        // 6) Kepala Madrasah: Supervisi penuh, laporan eksekutif, persetujuan
        $kamad = Role::findOrCreate('kepala_madrasah');
        $kamad->syncPermissions([
            'view_students', 'export_students',
            'manage_archives',
            'view_staff', 'approve_staff_changes',
            'view_rombels',
            'view_academic_years',
            'view_grades',
            'manage_bk',
            'view_ppdb',
            'view_letters',
            'print_documents',
            'view_emis',
            'validate_tpg',
            'view_audit_logs',
        ]);

        // 7) Admin: Seluruh hak akses teknis & manajemen madrasah,
        // KECUALI kewenangan pejabat substantif madrasah (verifikasi Kaur TU & persetujuan Kepala Madrasah)
        // sesuai aturan WP0.5 TAHAP 3 bahwa admin teknis tidak otomatis menjadi pejabat pemutus.
        $admin = Role::findOrCreate('admin');
        $substantiveDecisionPermissions = ['verify_staff_changes', 'approve_staff_changes'];
        $admin->syncPermissions(
            Permission::whereNotIn('name', $substantiveDecisionPermissions)->get()
        );

        // ── 3. Backfill User yang Ada ─────────────────────────────────
        foreach (User::all() as $user) {
            $targetRole = match ($user->role) {
                'admin' => 'admin',
                'staf' => 'staf', // Sesuai arahan pengguna: TETAP 'staf' dan JANGAN dilebur ke operator
                'operator' => 'operator',
                'kaur_tu' => 'kaur_tu',
                'kepala_madrasah' => 'kepala_madrasah',
                'pegawai' => 'pegawai',
                default => 'guru',
            };

            if (! $user->hasRole($targetRole)) {
                $user->syncRoles([$targetRole]);
            }
        }
    }
}
