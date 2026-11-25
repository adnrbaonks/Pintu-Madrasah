<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\StudentResource;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use OpenApi\Attributes as OA;

class StudentApiController extends Controller
{
    private const ALLOWED_INCLUDES = [
        'currentCohort' => ['currentCohort.rombel', 'currentCohort.academicYear'],
        'currentCohort.rombel.homeroomTeacher' => ['currentCohort.rombel.homeroomTeacher'],
        'cohorts' => ['cohorts.rombel', 'cohorts.academicYear'],
        'cohorts.rombel.homeroomTeacher' => ['cohorts.rombel.homeroomTeacher'],
        'documents' => ['documents'],
    ];

    #[OA\Get(
        path: '/api/v1/students',
        summary: 'Daftar Siswa',
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'academic_year_id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'updated_since', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'fields', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'include', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $with = ['currentCohort.rombel', 'currentCohort.academicYear'];

        if (is_string($request->query('include')) && $request->query('include') !== '') {
            foreach (array_map('trim', explode(',', $request->query('include'))) as $inc) {
                if (isset(self::ALLOWED_INCLUDES[$inc])) {
                    $with = array_values(array_unique(array_merge($with, self::ALLOWED_INCLUDES[$inc])));
                }
            }
        }

        $query = Student::query()->with($with);

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

        if ($updatedSince = $request->query('updated_since')) {
            try {
                $query->where('updated_at', '>=', Carbon::parse((string) $updatedSince));
            } catch (\Throwable) {
                // Abaikan format tanggal yang tidak valid agar tetap backward-compatible
            }
        }

        $perPage = min(max((int) $request->query('per_page', 50), 1), 100);
        $paginator = $query->paginate($perPage);

        $payload = $paginator->toArray();
        $payload['data'] = collect($paginator->items())
            ->map(fn (Student $student) => (new StudentResource($student))->toArray($request))
            ->all();
        $payload['meta'] = [
            'request_id' => $request->attributes->get('request_id'),
            'generated_at' => now()->toIso8601String(),
            'pagination' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ];

        return response()->json($payload);
    }

    #[OA\Get(
        path: '/api/v1/students/{nisn}',
        summary: 'Detail Biodata Siswa berdasarkan NISN (memerlukan scope students:identifiers atau students:pii)',
        parameters: [
            new OA\Parameter(name: 'nisn', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
            new OA\Response(response: 403, description: 'Forbidden scope'),
            new OA\Response(response: 404, description: 'Student not found'),
        ]
    )]
    public function show(Request $request, string $nisn): JsonResponse
    {
        $scopes = (array) $request->attributes->get('api_scopes', []);
        if (! in_array('students:identifiers', $scopes, true) && ! in_array('students:pii', $scopes, true)) {
            return response()->json([
                'success' => false,
                'message' => "Scope 'students:identifiers' atau 'students:pii' wajib dimiliki untuk pencarian berdasarkan NISN.",
                'error' => ['code' => 'FORBIDDEN_SCOPE'],
                'request_id' => $request->attributes->get('request_id'),
            ], 403);
        }

        $student = Student::where('nisn', $nisn)
            ->with($this->resolveShowIncludes($request))
            ->firstOrFail();

        return response()->json([
            'data' => (new StudentResource($student))->toArray($request),
            'meta' => [
                'request_id' => $request->attributes->get('request_id'),
                'generated_at' => now()->toIso8601String(),
            ],
        ]);
    }

    #[OA\Get(
        path: '/api/v1/students/by-id/{uuid}',
        summary: 'Detail Biodata Siswa berdasarkan UUID (mendukung siswa baru tanpa NISN)',
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
            new OA\Response(response: 404, description: 'Student not found'),
        ]
    )]
    public function showById(Request $request, string $uuid): JsonResponse
    {
        $student = Student::whereKey($uuid)
            ->with($this->resolveShowIncludes($request))
            ->firstOrFail();

        return response()->json([
            'data' => (new StudentResource($student))->toArray($request),
            'meta' => [
                'request_id' => $request->attributes->get('request_id'),
                'generated_at' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function resolveShowIncludes(Request $request): array
    {
        $with = ['cohorts.rombel', 'cohorts.academicYear', 'documents'];

        if (is_string($request->query('include')) && $request->query('include') !== '') {
            foreach (array_map('trim', explode(',', $request->query('include'))) as $inc) {
                if (isset(self::ALLOWED_INCLUDES[$inc])) {
                    $with = array_values(array_unique(array_merge($with, self::ALLOWED_INCLUDES[$inc])));
                }
            }
        }

        return $with;
    }
}
