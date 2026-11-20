<?php

namespace Tests\Feature;

use App\Jobs\PullEmisStudentDataJob;
use App\Models\PpdbPeriod;
use App\Models\PpdbRegistration;
use App\Models\Student;
use App\Models\User;
use App\Services\EmisService;
use App\Support\LocalTime;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class QuickWinsTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_dashboard_uses_correct_ppdb_constants(): void
    {
        $user = User::factory()->create();

        PpdbRegistration::factory()->create(['status' => PpdbRegistration::STATUS_REGISTERED]);
        PpdbRegistration::factory()->create(['status' => PpdbRegistration::STATUS_PLACED]);

        $this->actingAs($user)
            ->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard', false)
                ->where('stats.ppdbPending', 1)
                ->where('stats.ppdbAccepted', 1)
                ->where('stats.ppdbTotal', 2)
            );
    }

    public function test_ppdb_public_form_respects_open_and_close_dates(): void
    {
        // Out of date period
        PpdbPeriod::factory()->create([
            'is_active' => true,
            'is_public_form_active' => true,
            'open_date' => LocalTime::now()->subDays(10)->toDateString(),
            'close_date' => LocalTime::now()->subDays(2)->toDateString(),
        ]);

        $this->get(route('ppdb.public.form'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Ppdb/PublicForm', false)
                ->where('activePeriod', null)
            );

        // In-range period
        $active = PpdbPeriod::factory()->create([
            'is_active' => true,
            'is_public_form_active' => true,
            'open_date' => LocalTime::now()->subDays(1)->toDateString(),
            'close_date' => LocalTime::now()->addDays(5)->toDateString(),
        ]);

        $this->get(route('ppdb.public.form'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Ppdb/PublicForm', false)
                ->where('activePeriod.id', $active->id)
            );
    }

    public function test_pull_emis_student_job_stops_on_cancel_flag(): void
    {
        Cache::put('emis:sync:cancel', true, now()->addMinutes(5));

        $emis = new class extends EmisService
        {
            public function __construct() {}

            public function fetchAllStudents(int $page = 1, int $perPage = 100): array
            {
                return ['results' => [['id' => 1]], 'total' => 10, 'last_page' => 1];
            }
        };

        (new PullEmisStudentDataJob)->handle($emis);

        $progress = Cache::get('emis:pull:progress');
        $this->assertTrue($progress['cancelled'] ?? false);
        $this->assertNull(Cache::get('emis:sync:cancel'));
    }

    public function test_student_income_cast_is_string(): void
    {
        $student = Student::factory()->create([
            'father_income' => 'Rp 2.500.000',
            'mother_income' => '5000000',
        ]);

        $this->assertIsString($student->father_income);
        $this->assertIsString($student->mother_income);
    }
}
