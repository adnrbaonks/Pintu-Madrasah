<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\GradingFormula;
use App\Models\Rombel;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentGrade;
use App\Models\Subject;
use App\Models\User;
use App\Services\GradeService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class GradeCalculationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_grade_service_calculates_weighted_average_correctly(): void
    {
        $service = new GradeService;
        $academicYear = AcademicYear::factory()->create();

        // Formula khusus: P1=10, P2=10, STS=40, SAS=40
        GradingFormula::create([
            'academic_year_id' => $academicYear->id,
            'weight_p1' => 10,
            'weight_p2' => 10,
            'weight_sts' => 40,
            'weight_sas' => 40,
            'threshold_a' => 85,
            'threshold_b' => 75,
            'threshold_c' => 65,
        ]);

        // Nilai: P1=80, P2=90, STS=85, SAS=95
        // (80*0.1) + (90*0.1) + (85*0.4) + (95*0.4) = 8 + 9 + 34 + 38 = 89
        $result = $service->calculate([
            'nilai_p1' => 80,
            'nilai_p2' => 90,
            'nilai_sts' => 85,
            'nilai_sas' => 95,
        ], $academicYear);

        $this->assertEquals(89.0, $result['nilai_akhir']);
        $this->assertSame('A', $result['predikat']); // 89 >= 85
    }

    public function test_grade_service_normalizes_when_some_components_null(): void
    {
        $service = new GradeService;
        $academicYear = AcademicYear::factory()->create();

        // Default: 20, 20, 30, 30. Baru ada P1=80 dan STS=90. Total bobot aktif = 50%.
        // Score = (80*0.2) + (90*0.3) = 16 + 27 = 43. Di skala 100 => 43 / 0.5 = 86.
        $result = $service->calculate([
            'nilai_p1' => 80,
            'nilai_p2' => null,
            'nilai_sts' => 90,
            'nilai_sas' => null,
        ], $academicYear);

        $this->assertEquals(86.0, $result['nilai_akhir']);
        $this->assertSame('B', $result['predikat']); // Default A >= 90, B >= 80
    }

    public function test_student_grade_model_saves_calculated_final_grade_and_predicate(): void
    {
        $academicYear = AcademicYear::factory()->create(['is_active' => true]);
        $class = SchoolClass::factory()->create();
        $rombel = Rombel::factory()->create([
            'academic_year_id' => $academicYear->id,
            'class_id' => $class->id,
        ]);
        $student = Student::factory()->create();
        $subject = Subject::factory()->create();

        $grade = StudentGrade::create([
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'academic_year_id' => $academicYear->id,
            'rombel_id' => $rombel->id,
            'semester' => 'Ganjil',
            'nilai_p1' => 100,
            'nilai_p2' => 100,
            'nilai_sts' => 100,
            'nilai_sas' => 100,
        ]);

        $this->assertEquals(100.0, $grade->nilai_akhir);
        $this->assertSame('A', $grade->predikat);
    }

    public function test_upsert_grade_endpoint_computes_and_returns_final_grade(): void
    {
        $user = User::factory()->create(['role' => 'staf']);
        $academicYear = AcademicYear::factory()->create(['is_active' => true]);
        $class = SchoolClass::factory()->create();
        $rombel = Rombel::factory()->create([
            'academic_year_id' => $academicYear->id,
            'class_id' => $class->id,
        ]);
        $student = Student::factory()->create();
        $subject = Subject::factory()->create();

        $this->actingAs($user)->postJson(route('leger.grade.upsert'), [
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'rombel_id' => $rombel->id,
            'academic_year_id' => $academicYear->id,
            'semester' => 'Ganjil',
            'nilai_p1' => 90,
            'nilai_p2' => 90,
            'nilai_sts' => 90,
            'nilai_sas' => 90,
        ])->assertOk()
            ->assertJson([
                'nilai_akhir' => 90,
                'predikat' => 'A',
            ]);
    }

    public function test_formula_update_endpoint_validates_100_percent_total_weight(): void
    {
        $admin = User::factory()->admin()->create();
        $academicYear = AcademicYear::factory()->create();

        // Gagal jika total bukan 100%
        $this->actingAs($admin)->post(route('leger.formula.update'), [
            'academic_year_id' => $academicYear->id,
            'weight_p1' => 20,
            'weight_p2' => 20,
            'weight_sts' => 20,
            'weight_sas' => 20, // Total 80
            'threshold_a' => 90,
            'threshold_b' => 80,
            'threshold_c' => 70,
        ])->assertSessionHas('error');

        // Berhasil jika total 100%
        $this->actingAs($admin)->post(route('leger.formula.update'), [
            'academic_year_id' => $academicYear->id,
            'weight_p1' => 25,
            'weight_p2' => 25,
            'weight_sts' => 25,
            'weight_sas' => 25,
            'threshold_a' => 90,
            'threshold_b' => 80,
            'threshold_c' => 70,
        ])->assertSessionHas('success');

        $this->assertDatabaseHas('grading_formulas', [
            'academic_year_id' => $academicYear->id,
            'weight_p1' => 25.0,
        ]);
    }

    public function test_recalculate_command_updates_grades(): void
    {
        $academicYear = AcademicYear::factory()->create();
        $class = SchoolClass::factory()->create();
        $rombel = Rombel::factory()->create([
            'academic_year_id' => $academicYear->id,
            'class_id' => $class->id,
        ]);
        $student = Student::factory()->create();
        $subject = Subject::factory()->create();

        // Buat record nilai
        $grade = StudentGrade::create([
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'academic_year_id' => $academicYear->id,
            'rombel_id' => $rombel->id,
            'semester' => 'Ganjil',
            'nilai_p1' => 80,
            'nilai_p2' => 80,
            'nilai_sts' => 80,
            'nilai_sas' => 80,
        ]);

        // Ubah formula jadi A >= 70
        GradingFormula::create([
            'academic_year_id' => $academicYear->id,
            'weight_p1' => 25,
            'weight_p2' => 25,
            'weight_sts' => 25,
            'weight_sas' => 25,
            'threshold_a' => 70,
            'threshold_b' => 60,
            'threshold_c' => 50,
        ]);

        $this->artisan('grades:recalculate '.$academicYear->id)
            ->assertSuccessful();

        $this->assertSame('A', $grade->fresh()->predikat);
    }
}
