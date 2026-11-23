<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ClassResource;
use App\Models\Rombel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use OpenApi\Attributes as OA;

class ClassApiController extends Controller
{
    #[OA\Get(
        path: '/api/v1/classes',
        summary: 'Daftar Rombel Aktif dan Wali Kelas',
        parameters: [
            new OA\Parameter(name: 'academic_year_id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'updated_since', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $query = Rombel::with(['schoolClass', 'homeroomTeacher', 'academicYear']);

        if ($academicYearId = $request->query('academic_year_id')) {
            if ($academicYearId === 'active') {
                $query->whereHas('academicYear', fn ($y) => $y->where('is_active', true));
            } else {
                $query->where('academic_year_id', $academicYearId);
            }
        }

        if ($updatedSince = $request->query('updated_since')) {
            try {
                $query->where('updated_at', '>=', Carbon::parse((string) $updatedSince));
            } catch (\Throwable) {
                // Abaikan format tanggal yang tidak valid
            }
        }

        $classes = $query->get()
            ->map(fn (Rombel $rombel) => (new ClassResource($rombel))->toArray($request))
            ->all();

        return response()->json([
            'data' => $classes,
            'meta' => [
                'request_id' => $request->attributes->get('request_id'),
                'generated_at' => now()->toIso8601String(),
            ],
        ]);
    }
}
