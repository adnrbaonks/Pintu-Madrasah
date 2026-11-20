<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Student;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class StudentApiController extends Controller
{
    #[OA\Get(
        path: '/api/v1/students',
        summary: 'Daftar Siswa',
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'academic_year_id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
        ]
    )]
    public function index(Request $request)
    {
        $query = Student::query()
            ->with(['currentCohort.rombel', 'currentCohort.academicYear']);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($academicYearId = $request->query('academic_year_id')) {
            $query->whereHas('currentCohort', function ($q) use ($academicYearId) {
                if ($academicYearId === 'active') {
                    $q->whereHas('academicYear', fn ($y) => $y->where('is_active', true));
                } else {
                    $q->where('academic_year_id', $academicYearId);
                }
            });
        }

        $students = $query->paginate($request->query('per_page', 50));

        return response()->json($students);
    }

    #[OA\Get(
        path: '/api/v1/students/{nisn}',
        summary: 'Detail Biodata Siswa berdasarkan NISN',
        parameters: [
            new OA\Parameter(name: 'nisn', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
            new OA\Response(response: 404, description: 'Student not found'),
        ]
    )]
    public function show($nisn)
    {
        $student = Student::where('nisn', $nisn)->with(['cohorts.rombel', 'cohorts.academicYear', 'documents'])->firstOrFail();

        return response()->json([
            'data' => $student,
        ]);
    }
}
