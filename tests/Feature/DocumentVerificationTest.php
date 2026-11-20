<?php

namespace Tests\Feature;

use App\Models\DocumentVerification;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DocumentVerificationTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_print_biodata_stores_student_uuid_as_verifiable_id(): void
    {
        $user = User::factory()->create(['role' => 'staf']);
        $student = Student::factory()->create();

        $this->actingAs($user)
            ->get(route('students.print-biodata', $student))
            ->assertOk();

        $verification = DocumentVerification::where('document_type', DocumentVerification::TYPE_BIODATA)
            ->where('verifiable_type', Student::class)
            ->where('verifiable_id', $student->id)
            ->first();

        $this->assertNotNull($verification);
        $this->assertSame($student->id, $verification->verifiable_id);
        $this->assertTrue($verification->verifiable->is($student));
    }

    public function test_verifiable_id_column_accepts_uuid(): void
    {
        $type = strtolower((string) Schema::getColumnType('document_verifications', 'verifiable_id'));

        $this->assertContains($type, ['string', 'varchar', 'char', 'uuid']);
    }

    public function test_public_verification_page_still_works(): void
    {
        $student = Student::factory()->create();
        $verification = DocumentVerification::issue(DocumentVerification::TYPE_BIODATA, $student, [
            'nama' => $student->name,
            'madrasah' => 'MTs Contoh',
        ]);

        $this->get('/v/'.$verification->token)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Verify/Show', false)
                ->where('valid', true)
                ->where('documentType', 'biodata')
                ->where('snapshot.nama', $student->name));
    }
}
