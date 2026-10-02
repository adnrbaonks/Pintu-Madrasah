<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Rombel;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class ClassApiController extends Controller
{
    #[OA\Get(
        path: "/api/v1/classes",
        summary: "Daftar Rombel Aktif dan Wali Kelas",
        parameters: [
            new OA\Parameter(name: "academic_year_id", in: "query", required: false, schema: new OA\Schema(type: "string"))
        ],
        responses: [
            new OA\Response(response: 200, description: "Successful operation")
        ]
    )]
    public function index(Request $request)
    {
        $query = Rombel::with(['schoolClass', 'homeroomTeacher', 'academicYear']);

        if ($academicYearId = $request->query('academic_year_id')) {
            if ($academicYearId === 'active') {
                $query->whereHas('academicYear', fn($y) => $y->where('is_active', true));
            } else {
                $query->where('academic_year_id', $academicYearId);
            }
        }

        $classes = $query->get();

        return response()->json([
            'data' => $classes
        ]);
    }
}
