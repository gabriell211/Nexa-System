<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\CostCenterRequest;
use App\Http\Resources\CostCenterResource;
use App\Services\CustomerOrganizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

final class CostCenterController extends Controller
{
    public function __construct(private readonly CustomerOrganizationService $organization) {}

    public function index(Request $request, int $customer): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'page' => ['sometimes','integer','min:1'],
            'per_page' => ['sometimes','integer','between:1,100'],
            'active_only' => ['sometimes','boolean'],
        ]);
        $client = $request->attributes->get('nexa_tenant')->customers()->findOrFail($customer);
        return CostCenterResource::collection(
            $client->costCenters()
                ->when($request->boolean('active_only'), fn ($query) => $query->where('active', true))
                ->orderBy('code')->paginate($filters['per_page'] ?? 25)
        );
    }

    public function show(Request $request, int $customer, int $costCenter): CostCenterResource
    {
        $client = $request->attributes->get('nexa_tenant')->customers()->findOrFail($customer);
        return new CostCenterResource($client->costCenters()->findOrFail($costCenter));
    }

    public function store(CostCenterRequest $request, int $customer): JsonResponse
    {
        $model = $this->organization->createCostCenter(
            $request->attributes->get('nexa_tenant'), $request->user(), $customer, $request->validated()
        );
        return (new CostCenterResource($model))->response()->setStatusCode(201);
    }

    public function update(CostCenterRequest $request, int $customer, int $costCenter): CostCenterResource
    {
        return new CostCenterResource($this->organization->updateCostCenter(
            $request->attributes->get('nexa_tenant'), $request->user(), $customer, $costCenter, $request->validated()
        ));
    }

    public function destroy(Request $request, int $customer, int $costCenter): JsonResponse
    {
        $this->organization->deactivateCostCenter(
            $request->attributes->get('nexa_tenant'), $request->user(), $customer, $costCenter
        );
        return response()->json(null, 204);
    }
}
