<?php

namespace App\Jobs;

use App\Models\EmisGtkStaging;
use App\Models\Staff;
use App\Models\StaffChangeRequest;
use App\Services\OutboxService;
use App\Services\StaffChangeRequestService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

    /**
     * Field nullable pada tabel `staff` yang boleh dikosongkan secara eksplisit oleh EMIS.
     *
     * Kunci adalah kolom `staff`, nilai adalah daftar kunci mentah pada payload EMIS (`raw_data`).
     * Kolom NOT NULL (`full_name_with_titles`, `gender`, `birth_place`, `birth_date`,
     * `employment_status`, `tpg_status`, `is_active`) tidak dimasukkan agar tidak melanggar skema.
     *
     * @var array<string, array<int, string>>
     */
    private const CLEARABLE_FIELDS = [
        'nip' => ['nip'],
        'npk' => ['npk'],
        'nuptk' => ['nuptk'],
        'first_title' => ['first_title'],
        'last_title' => ['last_title'],
        'phone' => ['phone', 'telepon', 'no_hp'],
        'email' => ['email'],
        'civil_rank' => ['civil_rank', 'pangkat', 'golongan'],
        'civil_rank_tmt' => ['civil_rank_tmt', 'tmt_pangkat', 'tmt_golongan'],
        'functional_position' => ['fungsi', 'function_role', 'functional_position'],
        'highest_education' => ['highest_education', 'pendidikan_terakhir'],
        'university_name' => ['university_name', 'perguruan_tinggi'],
        'study_program' => ['study_program', 'prodi', 'program_studi'],
        'graduation_year' => ['graduation_year', 'tahun_lulus'],
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
                    DB::transaction(function () use ($existing, $staffData, &$failed, $staging): void {
                        OutboxService::withoutObserver(function () use ($existing, $staffData, &$failed, $staging): void {
                            if ($existing) {
                                $lockedStaff = Staff::withTrashed()->lockForUpdate()->findOrFail($existing->id);

                                if ($lockedStaff->trashed()) {
                                    $lockedStaff->restore();
                                }

                                // Rekonsiliasi pengajuan perubahan GTK yang berstatus pending_external_sync
                                $pendingRequests = StaffChangeRequest::with('items')
                                    ->where('staff_id', $lockedStaff->id)
                                    ->where('status', StaffChangeRequest::STATUS_PENDING_EXTERNAL_SYNC)
                                    ->lockForUpdate()
                                    ->get();

                                $updatePayload = $this->buildStaffUpdatePayload(
                                    $staging,
                                    $staffData,
                                    $pendingRequests,
                                );

                                if ($updatePayload !== []) {
                                    $lockedStaff->update($updatePayload);
                                }

                                foreach ($pendingRequests as $pendingReq) {
                                    app(StaffChangeRequestService::class)->reconcileExternalSync($pendingReq);
                                }

                                return;
                            }

                            $missing = $this->missingRequiredFields($staffData);

                            if ($missing !== []) {
                                $this->markFailed($staging, 'Data wajib belum lengkap: '.implode(', ', $missing));
                                $failed++;

                                return;
                            }

                            Staff::create($staffData);
                        });
                    });

                    if ($staging->status === 'failed') {
                        continue;
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
     * Bedakan field yang tidak dikirim dalam staging parsial vs field yang sengaja dikosongkan.
     *
     * @param  array<string, mixed>  $staffData
     * @param  Collection<int, StaffChangeRequest>  $pendingRequests
     * @return array<string, mixed>
     */
    private function buildStaffUpdatePayload(
        EmisGtkStaging $staging,
        array $staffData,
        $pendingRequests,
    ): array {
        $payload = [];

        foreach ($staffData as $field => $value) {
            if ($value !== null) {
                $payload[$field] = $value;

                continue;
            }

            if ($this->shouldApplyExplicitNull($staging, $field, $pendingRequests)) {
                $payload[$field] = null;
            }
        }

        return $payload;
    }

    /**
     * @param  Collection<int, StaffChangeRequest>  $pendingRequests
     */
    private function shouldApplyExplicitNull(
        EmisGtkStaging $staging,
        string $field,
        $pendingRequests,
    ): bool {
        if (! array_key_exists($field, self::CLEARABLE_FIELDS)) {
            return false;
        }

        // Jangan kosongkan kunci identitas jika akan menyisakan staf tanpa identitas sama sekali.
        if (in_array($field, self::IDENTITY_KEYS, true) && ! $this->hasOtherIdentityOnStaging($staging, $field)) {
            return false;
        }

        $rawData = is_array($staging->raw_data) ? $staging->raw_data : [];
        foreach (self::CLEARABLE_FIELDS[$field] as $rawKey) {
            if (array_key_exists($rawKey, $rawData) && ($rawData[$rawKey] === null || trim((string) $rawData[$rawKey]) === '')) {
                return true;
            }
        }

        foreach ($pendingRequests as $request) {
            foreach ($request->items as $item) {
                if (
                    $item->field === $field
                    && $item->apply_status === 'pending_external_sync'
                    && ($item->proposed_value === null || $item->proposed_value === '')
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    private function hasOtherIdentityOnStaging(EmisGtkStaging $staging, string $excludingField): bool
    {
        foreach (self::IDENTITY_KEYS as $column) {
            if ($column === $excludingField) {
                continue;
            }

            if (filled($staging->{$column})) {
                return true;
            }
        }

        return false;
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
