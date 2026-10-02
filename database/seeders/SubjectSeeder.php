<?php

namespace Database\Seeders;

use App\Models\Subject;
use Illuminate\Database\Seeder;

class SubjectSeeder extends Seeder
{
    public function run(): void
    {
        $subjects = [
            // Kelas 7 - Mapel Umum
            ['code' => 'PAI-7',    'name' => 'Pendidikan Agama Islam & Budi Pekerti', 'type' => 'agama',  'grade_level' => 7, 'kkm' => 75, 'order' => 1],
            ['code' => 'PKN-7',    'name' => 'Pendidikan Pancasila',                  'type' => 'umum',   'grade_level' => 7, 'kkm' => 70, 'order' => 2],
            ['code' => 'BIN-7',    'name' => 'Bahasa Indonesia',                      'type' => 'umum',   'grade_level' => 7, 'kkm' => 70, 'order' => 3],
            ['code' => 'MTK-7',    'name' => 'Matematika',                            'type' => 'umum',   'grade_level' => 7, 'kkm' => 70, 'order' => 4],
            ['code' => 'IPA-7',    'name' => 'Ilmu Pengetahuan Alam (IPA)',           'type' => 'umum',   'grade_level' => 7, 'kkm' => 70, 'order' => 5],
            ['code' => 'IPS-7',    'name' => 'Ilmu Pengetahuan Sosial (IPS)',         'type' => 'umum',   'grade_level' => 7, 'kkm' => 70, 'order' => 6],
            ['code' => 'BIG-7',    'name' => 'Bahasa Inggris',                        'type' => 'umum',   'grade_level' => 7, 'kkm' => 70, 'order' => 7],
            ['code' => 'PJOK-7',   'name' => 'Pendidikan Jasmani, OR & Kesehatan',   'type' => 'umum',   'grade_level' => 7, 'kkm' => 70, 'order' => 8],
            ['code' => 'INFTK-7',  'name' => 'Informatika',                           'type' => 'umum',   'grade_level' => 7, 'kkm' => 70, 'order' => 9],
            ['code' => 'SBP-7',    'name' => 'Seni Budaya & Prakarya',               'type' => 'umum',   'grade_level' => 7, 'kkm' => 70, 'order' => 10],
            ['code' => 'BAR-7',    'name' => 'Bahasa Arab',                           'type' => 'agama',  'grade_level' => 7, 'kkm' => 70, 'order' => 11],
            ['code' => 'FIKIH-7',  'name' => 'Fikih',                                'type' => 'agama',  'grade_level' => 7, 'kkm' => 75, 'order' => 12],
            ['code' => 'AQIDAH-7', 'name' => 'Akidah Akhlak',                        'type' => 'agama',  'grade_level' => 7, 'kkm' => 75, 'order' => 13],
            ['code' => 'SKI-7',    'name' => 'Sejarah Kebudayaan Islam (SKI)',        'type' => 'agama',  'grade_level' => 7, 'kkm' => 75, 'order' => 14],
            ['code' => 'MULOK-7',  'name' => 'Muatan Lokal',                         'type' => 'mulok',  'grade_level' => 7, 'kkm' => 70, 'order' => 15],

            // Kelas 8
            ['code' => 'PAI-8',    'name' => 'Pendidikan Agama Islam & Budi Pekerti', 'type' => 'agama',  'grade_level' => 8, 'kkm' => 75, 'order' => 1],
            ['code' => 'PKN-8',    'name' => 'Pendidikan Pancasila',                  'type' => 'umum',   'grade_level' => 8, 'kkm' => 70, 'order' => 2],
            ['code' => 'BIN-8',    'name' => 'Bahasa Indonesia',                      'type' => 'umum',   'grade_level' => 8, 'kkm' => 70, 'order' => 3],
            ['code' => 'MTK-8',    'name' => 'Matematika',                            'type' => 'umum',   'grade_level' => 8, 'kkm' => 70, 'order' => 4],
            ['code' => 'IPA-8',    'name' => 'Ilmu Pengetahuan Alam (IPA)',           'type' => 'umum',   'grade_level' => 8, 'kkm' => 70, 'order' => 5],
            ['code' => 'IPS-8',    'name' => 'Ilmu Pengetahuan Sosial (IPS)',         'type' => 'umum',   'grade_level' => 8, 'kkm' => 70, 'order' => 6],
            ['code' => 'BIG-8',    'name' => 'Bahasa Inggris',                        'type' => 'umum',   'grade_level' => 8, 'kkm' => 70, 'order' => 7],
            ['code' => 'PJOK-8',   'name' => 'Pendidikan Jasmani, OR & Kesehatan',   'type' => 'umum',   'grade_level' => 8, 'kkm' => 70, 'order' => 8],
            ['code' => 'INFTK-8',  'name' => 'Informatika',                           'type' => 'umum',   'grade_level' => 8, 'kkm' => 70, 'order' => 9],
            ['code' => 'SBP-8',    'name' => 'Seni Budaya & Prakarya',               'type' => 'umum',   'grade_level' => 8, 'kkm' => 70, 'order' => 10],
            ['code' => 'BAR-8',    'name' => 'Bahasa Arab',                           'type' => 'agama',  'grade_level' => 8, 'kkm' => 70, 'order' => 11],
            ['code' => 'FIKIH-8',  'name' => 'Fikih',                                'type' => 'agama',  'grade_level' => 8, 'kkm' => 75, 'order' => 12],
            ['code' => 'AQIDAH-8', 'name' => 'Akidah Akhlak',                        'type' => 'agama',  'grade_level' => 8, 'kkm' => 75, 'order' => 13],
            ['code' => 'SKI-8',    'name' => 'Sejarah Kebudayaan Islam (SKI)',        'type' => 'agama',  'grade_level' => 8, 'kkm' => 75, 'order' => 14],
            ['code' => 'MULOK-8',  'name' => 'Muatan Lokal',                         'type' => 'mulok',  'grade_level' => 8, 'kkm' => 70, 'order' => 15],

            // Kelas 9
            ['code' => 'PAI-9',    'name' => 'Pendidikan Agama Islam & Budi Pekerti', 'type' => 'agama',  'grade_level' => 9, 'kkm' => 75, 'order' => 1],
            ['code' => 'PKN-9',    'name' => 'Pendidikan Pancasila',                  'type' => 'umum',   'grade_level' => 9, 'kkm' => 70, 'order' => 2],
            ['code' => 'BIN-9',    'name' => 'Bahasa Indonesia',                      'type' => 'umum',   'grade_level' => 9, 'kkm' => 70, 'order' => 3],
            ['code' => 'MTK-9',    'name' => 'Matematika',                            'type' => 'umum',   'grade_level' => 9, 'kkm' => 70, 'order' => 4],
            ['code' => 'IPA-9',    'name' => 'Ilmu Pengetahuan Alam (IPA)',           'type' => 'umum',   'grade_level' => 9, 'kkm' => 70, 'order' => 5],
            ['code' => 'IPS-9',    'name' => 'Ilmu Pengetahuan Sosial (IPS)',         'type' => 'umum',   'grade_level' => 9, 'kkm' => 70, 'order' => 6],
            ['code' => 'BIG-9',    'name' => 'Bahasa Inggris',                        'type' => 'umum',   'grade_level' => 9, 'kkm' => 70, 'order' => 7],
            ['code' => 'PJOK-9',   'name' => 'Pendidikan Jasmani, OR & Kesehatan',   'type' => 'umum',   'grade_level' => 9, 'kkm' => 70, 'order' => 8],
            ['code' => 'INFTK-9',  'name' => 'Informatika',                           'type' => 'umum',   'grade_level' => 9, 'kkm' => 70, 'order' => 9],
            ['code' => 'SBP-9',    'name' => 'Seni Budaya & Prakarya',               'type' => 'umum',   'grade_level' => 9, 'kkm' => 70, 'order' => 10],
            ['code' => 'BAR-9',    'name' => 'Bahasa Arab',                           'type' => 'agama',  'grade_level' => 9, 'kkm' => 70, 'order' => 11],
            ['code' => 'FIKIH-9',  'name' => 'Fikih',                                'type' => 'agama',  'grade_level' => 9, 'kkm' => 75, 'order' => 12],
            ['code' => 'AQIDAH-9', 'name' => 'Akidah Akhlak',                        'type' => 'agama',  'grade_level' => 9, 'kkm' => 75, 'order' => 13],
            ['code' => 'SKI-9',    'name' => 'Sejarah Kebudayaan Islam (SKI)',        'type' => 'agama',  'grade_level' => 9, 'kkm' => 75, 'order' => 14],
            ['code' => 'MULOK-9',  'name' => 'Muatan Lokal',                         'type' => 'mulok',  'grade_level' => 9, 'kkm' => 70, 'order' => 15],
        ];

        foreach ($subjects as $subj) {
            Subject::firstOrCreate(['code' => $subj['code']], $subj);
        }

        $this->command->info('✅ ' . count($subjects) . ' mata pelajaran berhasil di-seed.');
    }
}
