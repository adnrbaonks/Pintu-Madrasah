<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Akun Admin Awal
    |--------------------------------------------------------------------------
    |
    | Dipakai DatabaseSeeder. Di staging/production, isi SEED_ADMIN_PASSWORD
    | secara eksplisit; bila kosong, seeder tidak membuat akun admin.
    |
    */

    'admin_username' => env('SEED_ADMIN_USERNAME', 'admin'),

    'admin_password' => env('SEED_ADMIN_PASSWORD'),

];
