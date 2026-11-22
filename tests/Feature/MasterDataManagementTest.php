<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Rombel;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentGrade;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MasterDataManagementTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_operator_can_manage_subjects_and_duplicate_codes_are_rejected(): void
    {
        $operator = User::factory()->create(['role' => 'operator']);

        $this->actingAs($operator)
            ->post('/master/subjects', [
                'code' => 'MTK',
                'name' => 'Matematika',
                'type' => 'umum',
                'grade_level' => 7,
                'kkm' => 75,
                'is_active' => true,
                'order' => 1,
            ])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('subjects', ['code' => 'MTK', 'kkm' => 75]);

        $this->actingAs($operator)
            ->post('/master/subjects', [
                'code' => 'MTK',
                'name' => 'Matematika Lanjutan',
                'type' => 'umum',
                'grade_level' => 8,
                'kkm' => 75,
                'is_active' => true,
                'order' => 2,
            ])
            ->assertSessionHasErrors('code');
    }

    public function test_subject_in_use_by_grades_cannot_be_deleted(): void
    {
        $admin = User::factory()->admin()->create();
        $subject = Subject::factory()->create();
        $academicYear = AcademicYear::factory()->create();
        $schoolClass = SchoolClass::factory()->create();
        $rombel = Rombel::factory()->create([
            'academic_year_id' => $academicYear->id,
            'class_id' => $schoolClass->id,
        ]);
        $student = Student::factory()->create();

        StudentGrade::create([
            'student_id' => $student->id,
            'subject_id' => $subject->id,
            'academic_year_id' => $academicYear->id,
            'rombel_id' => $rombel->id,
            'semester' => 'Ganjil',
        ]);

        $this->actingAs($admin)
            ->delete("/master/subjects/{$subject->id}")
            ->assertSessionHasErrors('delete');

        $this->assertModelExists($subject);
    }

    public function test_school_class_in_use_by_rombel_cannot_be_deleted(): void
    {
        $admin = User::factory()->admin()->create();
        $schoolClass = SchoolClass::factory()->create();
        $academicYear = AcademicYear::factory()->create();
        Rombel::factory()->create(['class_id' => $schoolClass->id, 'academic_year_id' => $academicYear->id]);

        $this->actingAs($admin)
            ->delete("/master/classes/{$schoolClass->id}")
            ->assertSessionHasErrors('delete');

        $this->assertModelExists($schoolClass);
    }

    public function test_academic_year_can_be_activated_and_locked_year_cannot_be_updated(): void
    {
        $admin = User::factory()->admin()->create();
        $oldYear = AcademicYear::factory()->active()->create();
        $newYear = AcademicYear::factory()->create(['year' => '2035/2036', 'semester' => 'Ganjil']);

        $this->actingAs($admin)
            ->post("/master/academic-years/{$newYear->id}/set-active")
            ->assertSessionHas('success');

        $this->assertFalse($oldYear->fresh()->is_active);
        $this->assertTrue($newYear->fresh()->is_active);

        $this->actingAs($admin)
            ->post("/master/academic-years/{$newYear->id}/toggle-lock")
            ->assertSessionHas('success');

        $this->actingAs($admin)
            ->put("/master/academic-years/{$newYear->id}", ['year' => '2036/2037', 'semester' => 'Genap'])
            ->assertSessionHasErrors('academic_year');
    }

    public function test_guru_cannot_access_master_data_routes(): void
    {
        $guru = User::factory()->create(['role' => 'guru']);

        $this->actingAs($guru)->get('/master/subjects')->assertForbidden();
        $this->actingAs($guru)->get('/master/classes')->assertForbidden();
        $this->actingAs($guru)->get('/master/academic-years')->assertForbidden();
    }

    public function test_master_data_index_pages_render(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get('/master/subjects')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('MasterData/Subjects/Index', false)->has('subjects'));
        $this->actingAs($admin)
            ->get('/master/classes')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('MasterData/Classes/Index', false)->has('classes'));
        $this->actingAs($admin)
            ->get('/master/academic-years')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('MasterData/AcademicYears/Index', false)->has('academicYears'));
    }
}
