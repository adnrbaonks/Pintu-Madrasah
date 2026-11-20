<?php

namespace App\Console\Commands;

use App\Models\StudentGrade;
use App\Services\GradeService;
use Illuminate\Console\Command;

class RecalculateGrades extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'grades:recalculate {academic_year_id?} {--dry-run : Hitung dan tampilkan simulasi tanpa menyimpan}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Hitung ulang nilai_akhir dan predikat student_grades berdasarkan formula tahun ajaran';

    /**
     * Execute the console command.
     */
    public function handle(GradeService $gradeService): int
    {
        $yearId = $this->argument('academic_year_id');
        $dryRun = (bool) $this->option('dry-run');

        $query = StudentGrade::query();
        if ($yearId) {
            $query->where('academic_year_id', $yearId);
        }

        $grades = $query->get();
        $total = $grades->count();

        $this->info("Menemukan {$total} baris nilai untuk dihitung ulang.".($dryRun ? ' (MODE SIMULASI / DRY-RUN)' : ''));

        $updated = 0;
        $changed = 0;

        foreach ($grades as $grade) {
            $calculated = $gradeService->calculate([
                'nilai_p1' => $grade->nilai_p1,
                'nilai_p2' => $grade->nilai_p2,
                'nilai_sts' => $grade->nilai_sts,
                'nilai_sas' => $grade->nilai_sas,
            ], $grade->academic_year_id);

            $oldAkhir = $grade->nilai_akhir;
            $oldPredikat = $grade->predikat;

            $newAkhir = $calculated['nilai_akhir'];
            $newPredikat = $calculated['predikat'];

            if ($oldAkhir != $newAkhir || $oldPredikat !== $newPredikat) {
                $changed++;
            }

            if (! $dryRun) {
                $grade->update([
                    'nilai_akhir' => $newAkhir,
                    'predikat' => $newPredikat,
                ]);
            }

            $updated++;
        }

        $this->info("Selesai. Diproses: {$updated}, Berubah: {$changed}.");

        return self::SUCCESS;
    }
}
