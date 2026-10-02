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

            foreach ($stagings as $staging) {
                $existing = Staff::withTrashed()
                    ->where('nik', $staging->nik)
                    ->orWhere(function ($q) use ($staging) {
                        if ($staging->nuptk) $q->orWhere('nuptk', $staging->nuptk);
                        if ($staging->nip) $q->orWhere('nip', $staging->nip);
                        if ($staging->npk) $q->orWhere('npk', $staging->npk);
                    })
                    ->first();

                $staffData = [
                    'nip' => $staging->nip,
                    'nik' => $staging->nik,
                    'npk' => $staging->npk,
                    'nuptk' => $staging->nuptk,
                    'full_name_with_titles' => $staging->full_name_with_titles,
                    'first_title' => $staging->first_title,
                    'last_title' => $staging->last_title,
                    'gender' => $staging->gender ?? 'L',
                    'birth_place' => $staging->birth_place ?? '-',
                    'birth_date' => $staging->birth_date ?? '1970-01-01',
                    'phone' => $staging->phone,
                    'email' => $staging->email,
                    'employment_status' => $staging->employment_status ?? 'gtt',
                    'civil_rank' => $staging->civil_rank,
                    'civil_rank_tmt' => $staging->civil_rank_tmt,
                    'functional_position' => $staging->functional_position,
                    'highest_education' => $staging->highest_education,
                    'university_name' => $staging->university_name,
                    'study_program' => $staging->study_program,
                    'graduation_year' => $staging->graduation_year,
                    'is_active' => true,
                ];

                if ($existing) {
                    if ($existing->trashed()) {
                        $existing->restore();
                    }
                    $existing->update($staffData);
                } else {
                    Staff::create($staffData);
                }

                $staging->update(['status' => 'committed']);
                $processed++;

                if ($processed % 10 === 0) {
                    $progress = min(100, round(($processed / $total) * 100));
                    Cache::put('emis_gtk:commit:progress', [
                        'progress' => $progress,
                        'status' => "Menyimpan $processed dari $total staf...",
                    ], now()->addHours(2));
                }
            }

            Cache::put('emis_gtk:commit:progress', [
                'progress' => 100,
                'status' => "Selesai! $processed data GTK berhasil disimpan.",
            ], now()->addHours(2));

            Log::info("[EMIS GTK] Commit selesai. Total: $processed");

        } catch (\Exception $e) {
            Log::error('[EMIS GTK] Error CommitEmisGtkStagingJob: ' . $e->getMessage());
            Cache::put('emis_gtk:commit:progress', [
                'progress' => 100,
                'status' => 'Gagal: ' . $e->getMessage(),
                'error' => true,
            ], now()->addHours(2));
        }
    }
}
