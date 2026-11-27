<?php

namespace Tests\Feature;

use App\Models\EmisReconciliationItem;
use App\Services\EmisReconciliationService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Benchmark sintetis rekonsiliasi siswa. Hanya jalan bila EMIS_BENCH_SIZES diset,
 * mis. EMIS_BENCH_SIZES=500,5000,10000. Hasil ditulis ke storage/app/emis-benchmark.json.
 */
class EmisReconciliationBenchmarkTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_benchmark_student_reconciliation(): void
    {
        $sizes = array_filter(array_map('intval', explode(',', (string) env('EMIS_BENCH_SIZES', ''))));
        if ($sizes === []) {
            $this->markTestSkipped('Set EMIS_BENCH_SIZES untuk menjalankan benchmark.');
        }

        $results = [];
        foreach ($sizes as $n) {
            DB::table('emis_reconciliation_items')->delete();
            DB::table('emis_import_batches')->delete();
            DB::table('emis_staging')->delete();
            DB::table('students')->delete();
            $now = now()->toDateTimeString();
            $students = [];
            $stagings = [];
            for ($i = 1; $i <= $n; $i++) {
                $nisn = '7'.str_pad((string) $i, 9, '0', STR_PAD_LEFT);
                $nik = '32'.str_pad((string) $i, 14, '0', STR_PAD_LEFT);
                $students[] = ['id' => (string) Str::uuid(), 'nisn' => $nisn, 'nik' => $nik, 'name' => "SISWA $i", 'gender' => 'L', 'birth_place' => 'BANDUNG', 'birth_date' => '2010-01-01', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now];
                if ($i % 10 !== 0) { // 10% pintu_only
                    $stagings[] = ['id' => (string) Str::uuid(), 'source' => 'bench', 'nisn' => $nisn, 'nik' => $nik, 'name' => $i % 7 === 0 ? "BEDA $i" : "SISWA $i", 'status' => 'pending', 'raw_data' => '[]', 'created_at' => $now, 'updated_at' => $now];
                }
            }
            foreach (array_chunk($students, 500) as $c) {
                DB::table('students')->insert($c);
            }
            foreach (array_chunk($stagings, 500) as $c) {
                DB::table('emis_staging')->insert($c);
            }

            DB::enableQueryLog();
            DB::flushQueryLog();
            gc_collect_cycles();
            $memBefore = memory_get_usage(true);
            $t = microtime(true);
            $batch = app(EmisReconciliationService::class)->reconcileStudents();
            $secs = microtime(true) - $t;
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            $items = EmisReconciliationItem::where('batch_id', $batch->id)->count();
            $this->assertSame($batch->same_count ?? $batch->matched_identical_count, $batch->matched_identical_count);
            $this->assertSame($items, $batch->matched_identical_count + $batch->difference_count + $batch->pintu_only_count + $batch->emis_only_count + $batch->needs_review_count);
            $results[$n] = [
                'duration_s' => round($secs, 2),
                'peak_memory_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
                'memory_delta_mb' => round((memory_get_usage(true) - $memBefore) / 1048576, 1),
                'queries' => $queries,
                'items' => $items,
                'identical' => $batch->matched_identical_count,
                'different' => $batch->difference_count,
                'pintu_only' => $batch->pintu_only_count,
                'emis_only' => $batch->emis_only_count,
                'needs_review' => $batch->needs_review_count,
                'driver' => DB::getDriverName(),
            ];
        }
        file_put_contents(storage_path('app/emis-benchmark-'.DB::getDriverName().'.json'), json_encode($results, JSON_PRETTY_PRINT));
        $this->assertNotEmpty($results);
    }
}
