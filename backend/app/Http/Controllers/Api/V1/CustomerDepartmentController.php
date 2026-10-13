<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\CustomerDepartmentRequest;
use App\Http\Resources\CustomerDepartmentResource;
use App\Services\CustomerOrganizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

final class CustomerDepartmentController extends Controller
{
    public function __construct(private readonly CustomerOrganizationService $organization) {}

    public function index(Request $request, int $customer, int $location): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'page' => ['sometimes','integer','min:1'],
            'per_page' => ['sometimes','integer','between:1,100'],
            'active_only' => ['sometimes','boolean'],
        ]);
        $client = $request->attributes->get('nexa_tenant')->customers()->findOrFail($customer);
        $unit = $client->locations()->findOrFail($location);
        return CustomerDepartmentResource::collection(
            $unit->departments()
                ->when($request->boolean('active_only'), fn ($query) => $query->where('active', true))
                ->orderBy('name')->paginate($filters['per_page'] ?? 25)
        );
    }

    public function show(Request $request, int $customer, int $location, int $department): CustomerDepartmentResource
    {
        $client = $request->attributes->get('nexa_tenant')->customers()->findOrFail($customer);
        $unit = $client->locations()->findOrFail($location);
        return new CustomerDepartmentResource($unit->departments()->findOrFail($department));
    }

    public function store(CustomerDepartmentRequest $request, int $customer, int $location): JsonResponse
    {
        $model = $this->organization->createDepartment(
            $request->attributes->get('nexa_tenant'), $request->user(), $customer, $location, $request->validated()
        );
        return (new CustomerDepartmentResource($model))->response()->setStatusCode(201);
    }

    public function update(CustomerDepartmentRequest $request, int $customer, int $location, int $department): CustomerDepartmentResource
    {
        return new CustomerDepartmentResource($this->organization->updateDepartment(
            $request->attributes->get('nexa_tenant'), $request->user(), $customer, $location, $department, $request->validated()
        ));
    }

    public function destroy(Request $request, int $customer, int $location, int $department): JsonResponse
    {
        $this->organization->deactivateDepartment(
            $request->attributes->get('nexa_tenant'), $request->user(), $customer, $location, $department
        );
        return response()->json(null, 204);
    }
}
