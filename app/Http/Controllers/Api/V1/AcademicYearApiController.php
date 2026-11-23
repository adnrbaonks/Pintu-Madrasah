<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\AcademicYearResource;
use App\Models\AcademicYear;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class AcademicYearApiController extends Controller
{
    #[OA\Get(
        path: '/api/v1/academic-years/active',
        summary: 'Informasi Tahun Ajaran Aktif',
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
            new OA\Response(response: 404, description: 'No active academic year'),
        ]
    )]
    public function active(Request $request): JsonResponse
    {
        $activeYear = AcademicYear::active();

        if (! $activeYear) {
            return response()->json([
                'success' => false,
                'message' => 'No active academic year found',
                'error' => ['code' => 'RESOURCE_NOT_FOUND'],
                'request_id' => $request->attributes->get('request_id'),
            ], 404);
        }

        return response()->json([
            'data' => (new AcademicYearResource($activeYear))->toArray($request),
            'meta' => [
                'request_id' => $request->attributes->get('request_id'),
                'generated_at' => now()->toIso8601String(),
            ],
        ]);
    }
}
