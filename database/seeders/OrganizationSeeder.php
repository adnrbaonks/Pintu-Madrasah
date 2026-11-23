<?php

namespace Database\Seeders;

use App\Models\OrganizationUnit;
use App\Models\Position;
use Illuminate\Database\Seeder;

class OrganizationSeeder extends Seeder
{
    public function run(): void
    {
        $pimpinan = OrganizationUnit::firstOrCreate(
            ['code' => 'PIMP'],
            [
                'name' => 'Pimpinan Madrasah',
                'type' => 'pimpinan',
                'is_active' => true,
            ],
        );

        $subUnits = [
            'TU' => ['name' => 'Urusan Tata Usaha', 'type' => 'tata_usaha'],
            'KUR' => ['name' => 'Bidang Kurikulum & Akademik', 'type' => 'kurikulum'],
            'KES' => ['name' => 'Bidang Kesiswaan & Karakter', 'type' => 'kesiswaan'],
            'SAR' => ['name' => 'Bidang Sarana & Prasarana', 'type' => 'sarana'],
        ];

        $unitMap = ['PIMP' => $pimpinan];

        foreach ($subUnits as $code => $meta) {
            $unitMap[$code] = OrganizationUnit::firstOrCreate(
                ['code' => $code],
                [
                    'parent_id' => $pimpinan->id,
                    'name' => $meta['name'],
                    'type' => $meta['type'],
                    'is_active' => true,
                ],
            );
        }

        $positions = [
            ['code' => 'KAMAD', 'unit' => 'PIMP', 'name' => 'Kepala Madrasah', 'level' => 1, 'is_structural' => true],
            ['code' => 'KAUR-TU', 'unit' => 'TU', 'name' => 'Kepala Urusan Tata Usaha', 'level' => 2, 'is_structural' => true],
            ['code' => 'OP-EMIS', 'unit' => 'TU', 'name' => 'Operator Data & EMIS', 'level' => 3, 'is_structural' => false],
            ['code' => 'STAF-TU', 'unit' => 'TU', 'name' => 'Staf Administrasi Tata Usaha', 'level' => 4, 'is_structural' => false],
            ['code' => 'WAKA-KUR', 'unit' => 'KUR', 'name' => 'Wakil Kepala Bidang Kurikulum', 'level' => 2, 'is_structural' => true],
            ['code' => 'GURU-MAPEL', 'unit' => 'KUR', 'name' => 'Guru Mata Pelajaran', 'level' => 3, 'is_structural' => false],
            ['code' => 'WAKA-KES', 'unit' => 'KES', 'name' => 'Wakil Kepala Bidang Kesiswaan', 'level' => 2, 'is_structural' => true],
            ['code' => 'GURU-BK', 'unit' => 'KES', 'name' => 'Koordinator BK & Pembinaan', 'level' => 3, 'is_structural' => false],
            ['code' => 'WAKA-SAR', 'unit' => 'SAR', 'name' => 'Wakil Kepala Bidang Sarana & Prasarana', 'level' => 2, 'is_structural' => true],
        ];

        foreach ($positions as $pos) {
            Position::firstOrCreate(
                ['code' => $pos['code']],
                [
                    'org_unit_id' => $unitMap[$pos['unit']]->id,
                    'name' => $pos['name'],
                    'level' => $pos['level'],
                    'is_structural' => $pos['is_structural'],
                ],
            );
        }
    }
}
