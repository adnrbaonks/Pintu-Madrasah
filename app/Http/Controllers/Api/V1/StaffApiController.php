<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\StaffResource;
use App\Models\Staff;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use OpenApi\Attributes as OA;

class StaffApiController extends Controller
{
    private const ALLOWED_INCLUDES = [
        'positionAssignments' => ['positionAssignments.position', 'positionAssignments.unit'],
    ];

    #[OA\Get(
        path: '/api/v1/staff',
        summary: 'Daftar Guru dan Staf TU',
        parameters: [
            new OA\Parameter(name: 'is_active', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
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
        $query = Staff::query();

        if (is_string($request->query('include')) && $request->query('include') !== '') {
            $with = [];
            foreach (array_map('trim', explode(',', $request->query('include'))) as $inc) {
                if (isset(self::ALLOWED_INCLUDES[$inc])) {
                    $with = array_values(array_unique(array_merge($with, self::ALLOWED_INCLUDES[$inc])));
                }
            }
            if (! empty($with)) {
                $query->with($with);
            }
        }

        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($updatedSince = $request->query('updated_since')) {
            try {
                $query->where('updated_at', '>=', Carbon::parse((string) $updatedSince));
            } catch (\Throwable) {
                // Abaikan format tanggal yang tidak valid
            }
        }

        $perPage = min(max((int) $request->query('per_page', 50), 1), 100);
        $paginator = $query->paginate($perPage);

        $payload = $paginator->toArray();
        $payload['data'] = collect($paginator->items())
            ->map(fn (Staff $staff) => (new StaffResource($staff))->toArray($request))
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
}
