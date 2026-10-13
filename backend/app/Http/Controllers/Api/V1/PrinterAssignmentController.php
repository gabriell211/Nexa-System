<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\PrinterAssignmentRequest;
use App\Http\Resources\PrinterAssignmentResource;
use App\Services\PrinterAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

final class PrinterAssignmentController extends Controller
{
    public function __construct(private readonly PrinterAssignmentService $assignments) {}

    public function index(Request $request, int $printer): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'page' => ['sometimes','integer','min:1'],
        ]);
        $tenant = $request->attributes->get('nexa_tenant');
        $device = $tenant->printers()->findOrFail($printer);
        return PrinterAssignmentResource::collection($device->assignments()
            ->with(['location:id,name', 'department:id,name', 'costCenter:id,name,code', 'actor:id,name'])
            ->orderByDesc('assigned_at')->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 25));
    }

    public function store(PrinterAssignmentRequest $request, int $printer): JsonResponse
    {
        $result = $this->assignments->assign(
            $request->attributes->get('nexa_tenant'), $request->user(), $printer, $request->validated()
        );
        return (new PrinterAssignmentResource($result))->response()->setStatusCode(201);
    }

    public function release(Request $request, int $printer): JsonResponse
    {
        $input = $request->validate(['reason' => ['required','string','min:5','max:500']]);
        $this->assignments->release($request->attributes->get('nexa_tenant'), $request->user(), $printer, $input['reason']);
        return response()->json(null, 204);
    }
}
