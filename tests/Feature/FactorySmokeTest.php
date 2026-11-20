<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\PpdbPeriod;
use App\Models\PpdbRegistration;
use App\Models\Rombel;
use App\Models\SchoolClass;
use App\Models\Staff;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class FactorySmokeTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_domain_factories_can_create_records(): void
    {
        $student = Student::factory()->create();
        $staff = Staff::factory()->create();
        $year = AcademicYear::factory()->active()->create();
        $class = SchoolClass::factory()->create();
        $rombel = Rombel::factory()->create([
            'academic_year_id' => $year->id,
            'class_id' => $class->id,
        ]);
        $subject = Subject::factory()->create();
        $period = PpdbPeriod::factory()->active()->create([
            'academic_year_id' => $year->id,
        ]);
        $registration = PpdbRegistration::factory()->create([
            'ppdb_period_id' => $period->id,
            'academic_year_id' => $year->id,
        ]);

        $this->assertTrue($student->exists);
        $this->assertSame('active', $student->status);
        $this->assertTrue($staff->is_active);
        $this->assertTrue($year->is_active);
        $this->assertSame($year->id, $rombel->academic_year_id);
        $this->assertTrue($subject->is_active);
        $this->assertTrue($period->is_public_form_active);
        $this->assertSame(PpdbRegistration::STATUS_REGISTERED, $registration->status);
    }

    public function test_database_seeder_is_idempotent(): void
    {
        $this->seed();
        $this->seed();

        $admin = User::where('username', 'admin')->first();

        $this->assertNotNull($admin);
        $this->assertTrue($admin->hasRole('admin'));
        $this->assertTrue(Hash::check('password', $admin->password));
        $this->assertSame(1, User::where('username', 'admin')->count());
    }
}
