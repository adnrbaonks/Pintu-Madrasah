<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Kamus Kepemilikan Data (Data Ownership Dictionary)
    |--------------------------------------------------------------------------
    |
    | Memetakan entitas dan atribut data ke sistem pemilik otoritatif:
    | - owner_system: 'emis' (Kemenag) | 'local' (PINTU Madrasah)
    | - change_policy:
    |     - 'read_only': data bersumber dari otoritas pusat (EMIS/Simpatika),
    |       tidak dapat diubah langsung dari form lokal madrasah.
    |     - 'direct': data operasional madrasah, dapat diubah oleh staf/admin.
    |     - 'approval_required': data yang memerlukan persetujuan kepala madrasah.
    | - sensitive: true jika data mengandung PII atau data rahasia.
    |
    */

    'staff' => [
        // Identitas Resmi (Otoritas EMIS / Dukcapil)
        'nik' => [
            'owner_system' => 'emis',
            'change_policy' => 'read_only',
            'sensitive' => true,
            'label' => 'Nomor Induk Kependudukan (NIK)',
        ],
        'nip' => [
            'owner_system' => 'emis',
            'change_policy' => 'read_only',
            'sensitive' => false,
            'label' => 'Nomor Induk Pegawai (NIP)',
        ],
        'nuptk' => [
            'owner_system' => 'emis',
            'change_policy' => 'read_only',
            'sensitive' => false,
            'label' => 'NUPTK',
        ],
        'npk' => [
            'owner_system' => 'emis',
            'change_policy' => 'read_only',
            'sensitive' => false,
            'label' => 'NPK',
        ],
        'gender' => [
            'owner_system' => 'emis',
            'change_policy' => 'read_only',
            'sensitive' => false,
            'label' => 'Jenis Kelamin',
        ],
        'birth_place' => [
            'owner_system' => 'emis',
            'change_policy' => 'read_only',
            'sensitive' => false,
            'label' => 'Tempat Lahir',
        ],
        'birth_date' => [
            'owner_system' => 'emis',
            'change_policy' => 'read_only',
            'sensitive' => true,
            'label' => 'Tanggal Lahir',
        ],
        'tpg_status' => [
            'owner_system' => 'emis',
            'change_policy' => 'read_only',
            'sensitive' => false,
            'label' => 'Status TPG / Sertifikasi',
        ],
        'civil_rank' => [
            'owner_system' => 'emis',
            'change_policy' => 'read_only',
            'sensitive' => false,
            'label' => 'Pangkat / Golongan',
        ],
        'civil_rank_tmt' => [
            'owner_system' => 'emis',
            'change_policy' => 'read_only',
            'sensitive' => false,
            'label' => 'TMT Golongan',
        ],

        // Data Lokal & Kontak Madrasah (Boleh Diubah Madrasah)
        'full_name_with_titles' => [
            'owner_system' => 'local',
            'change_policy' => 'direct',
            'sensitive' => false,
            'label' => 'Nama Lengkap Beserta Gelar',
        ],
        'first_title' => [
            'owner_system' => 'local',
            'change_policy' => 'direct',
            'sensitive' => false,
            'label' => 'Gelar Depan',
        ],
        'last_title' => [
            'owner_system' => 'local',
            'change_policy' => 'direct',
            'sensitive' => false,
            'label' => 'Gelar Belakang',
        ],
        'phone' => [
            'owner_system' => 'local',
            'change_policy' => 'direct',
            'sensitive' => true,
            'label' => 'Nomor Telepon / WhatsApp',
        ],
        'email' => [
            'owner_system' => 'local',
            'change_policy' => 'direct',
            'sensitive' => true,
            'label' => 'Email',
        ],
        'employment_status' => [
            'owner_system' => 'local',
            'change_policy' => 'direct',
            'sensitive' => false,
            'label' => 'Status Kepegawaian (PNS/PPPK/Honorer)',
        ],
        'functional_position' => [
            'owner_system' => 'local',
            'change_policy' => 'direct',
            'sensitive' => false,
            'label' => 'Jabatan Fungsional',
        ],
        'highest_education' => [
            'owner_system' => 'local',
            'change_policy' => 'direct',
            'sensitive' => false,
            'label' => 'Pendidikan Terakhir',
        ],
        'university_name' => [
            'owner_system' => 'local',
            'change_policy' => 'direct',
            'sensitive' => false,
            'label' => 'Nama Perguruan Tinggi',
        ],
        'study_program' => [
            'owner_system' => 'local',
            'change_policy' => 'direct',
            'sensitive' => false,
            'label' => 'Program Studi',
        ],
        'graduation_year' => [
            'owner_system' => 'local',
            'change_policy' => 'direct',
            'sensitive' => false,
            'label' => 'Tahun Lulus',
        ],
        'is_active' => [
            'owner_system' => 'local',
            'change_policy' => 'direct',
            'sensitive' => false,
            'label' => 'Status Aktif',
        ],
    ],
];
