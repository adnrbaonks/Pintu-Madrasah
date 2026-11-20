<?php

namespace App\Jobs;

use App\Models\EmisGtkStaging;
use App\Models\Staff;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CommitEmisGtkStagingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Urutan kunci identitas. NIK paling kuat, lalu NUPTK, NIP, dan NPK.
     *
     * @var array<int, string>
     */
    private const IDENTITY_KEYS = ['nik', 'nuptk', 'nip', 'npk'];

    /**
     * Kolom staff yang wajib terisi saat membuat staf baru.
     *
     * @var array<int, string>
     */
    private const REQUIRED_FIELDS = [
        'full_name_with_titles',
        'gender',
        'birth_place',
        'birth_date',
        'employment_status',
    ];

    public $timeout = 600;

    public function handle(): void
    {
        Log::info('[EMIS GTK] Memulai commit data GTK dari staging...');

        Cache::put('emis_gtk:commit:progress', ['progress' => 0, 'status' => 'Memulai sinkronisasi ke tabel staf...'], now()->addHours(2));

        try {
            $stagings = EmisGtkStaging::where('status', 'pending')->get();
            $total = $stagings->count();

            if ($total === 0) {
                Cache::put('emis_gtk:commit:progress', ['progress' => 100, 'status' => 'Tidak ada data staging GTK untuk disimpan.'], now()->addHours(2));

                return;
            }

            $processed = 0;
            $failed = 0;

            foreach ($stagings as $staging) {
                if (! $this->hasIdentity($staging)) {
                    $this->markFailed($staging, 'Identitas (NIK/NUPTK/NIP/NPK) tidak lengkap');
                    $failed++;

                    continue;
                }

                $existing = $this->findExistingStaff($staging);
                $staffData = $this->staffData($staging);

                try {
                    if ($existing) {
                        if ($existing->trashed()) {
                            $existing->restore();
                        }

                        $existing->update(array_filter($staffData, fn ($value) => $value !== null));
                    } else {
                        $missing = $this->missingRequiredFields($staffData);

                        if ($missing !== []) {
                            $this->markFailed($staging, 'Data wajib belum lengkap: '.implode(', ', $missing));
                            $failed++;

                            continue;
                        }

                        Staff::create($staffData);
                    }

                    $staging->update(['status' => 'committed', 'error_message' => null]);
                    $processed++;
                } catch (\Throwable $e) {
                    $this->markFailed($staging, $e->getMessage());
                    $failed++;
                    Log::warning('[EMIS GTK] Gagal commit staging', [
                        'staging_id' => $staging->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                if (($processed + $failed) % 10 === 0) {
                    $progress = min(100, round((($processed + $failed) / $total) * 100));
                    Cache::put('emis_gtk:commit:progress', [
                        'progress' => $progress,
                        'status' => "Menyimpan {$processed} dari {$total} staf...",
                    ], now()->addHours(2));
                }
            }

            Cache::put('emis_gtk:commit:progress', [
                'progress' => 100,
                'status' => "Selesai! {$processed} data GTK tersimpan, {$failed} gagal.",
            ], now()->addHours(2));

            Log::info("[EMIS GTK] Commit selesai. Berhasil: {$processed}, gagal: {$failed}");
        } catch (\Exception $e) {
            Log::error('[EMIS GTK] Error CommitEmisGtkStagingJob: '.$e->getMessage());
            Cache::put('emis_gtk:commit:progress', [
                'progress' => 100,
                'status' => 'Gagal: '.$e->getMessage(),
                'error' => true,
            ], now()->addHours(2));
        }
    }

    /**
     * Cari staff yang sudah ada memakai kunci identitas berurutan.
     */
    private function findExistingStaff(EmisGtkStaging $staging): ?Staff
    {
        foreach (self::IDENTITY_KEYS as $column) {
            $value = $staging->{$column};

            if (blank($value)) {
                continue;
            }

            $staff = Staff::withTrashed()->where($column, $value)->first();

            if ($staff) {
                return $staff;
            }
        }

        return null;
    }

    private function hasIdentity(EmisGtkStaging $staging): bool
    {
        foreach (self::IDENTITY_KEYS as $column) {
            if (filled($staging->{$column})) {
                return true;
            }
        }

        return false;
    }

    /**
     * Data yang dikirim ke tabel staff. Nilai kosong tidak diisi default palsu.
     *
     * @return array<string, mixed>
     */
    private function staffData(EmisGtkStaging $staging): array
    {
        $data = [
            'nip' => $staging->nip,
            'nik' => $staging->nik,
            'npk' => $staging->npk,
            'nuptk' => $staging->nuptk,
            'full_name_with_titles' => $staging->full_name_with_titles,
            'first_title' => $staging->first_title,
            'last_title' => $staging->last_title,
            'gender' => $staging->gender,
            'birth_place' => $staging->birth_place,
            'birth_date' => $staging->birth_date,
            'phone' => $staging->phone,
            'email' => $staging->email,
            'employment_status' => $staging->employment_status,
            'civil_rank' => $staging->civil_rank,
            'civil_rank_tmt' => $staging->civil_rank_tmt,
            'tpg_status' => $staging->tpg_status,
            'functional_position' => $staging->functional_position,
            'highest_education' => $staging->highest_education,
            'university_name' => $staging->university_name,
            'study_program' => $staging->study_program,
            'graduation_year' => $staging->graduation_year,
        ];

        if ($staging->k_aktif !== null) {
            $data['is_active'] = $staging->k_aktif;
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $staffData
     * @return array<int, string>
     */
    private function missingRequiredFields(array $staffData): array
    {
        return array_values(array_filter(
            self::REQUIRED_FIELDS,
            fn (string $field) => blank($staffData[$field] ?? null),
        ));
    }

    private function markFailed(EmisGtkStaging $staging, string $message): void
    {
        $staging->update([
            'status' => 'failed',
            'error_message' => $message,
        ]);
    }
}
