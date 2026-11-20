<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Staff;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class StaffApiController extends Controller
{
    #[OA\Get(
        path: '/api/v1/staff',
        summary: 'Daftar Guru dan Staf TU',
        parameters: [
            new OA\Parameter(name: 'is_active', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
        ]
    )]
    public function index(Request $request)
    {
        $query = Staff::query();

        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        $staff = $query->paginate($request->query('per_page', 50));

        return response()->json($staff);
    }
}
