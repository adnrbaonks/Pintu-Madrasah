<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use OpenApi\Attributes as OA;

class AcademicYearApiController extends Controller
{
    #[OA\Get(
        path: "/api/v1/academic-years/active",
        summary: "Informasi Tahun Ajaran Aktif",
        responses: [
            new OA\Response(response: 200, description: "Successful operation"),
            new OA\Response(response: 404, description: "No active academic year")
        ]
    )]
    public function active()
    {
        $activeYear = AcademicYear::active();

        if (!$activeYear) {
            return response()->json(['message' => 'No active academic year found'], 404);
        }

        return response()->json([
            'data' => collect($activeYear->toArray())->merge([
                'full_label' => $activeYear->full_label
            ])
        ]);
    }
}
