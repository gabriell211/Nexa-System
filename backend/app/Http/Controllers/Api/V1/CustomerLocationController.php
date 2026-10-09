<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\CustomerLocationRequest;
use App\Http\Resources\CustomerLocationResource;
use App\Services\CustomerOrganizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

final class CustomerLocationController extends Controller
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
        return CustomerLocationResource::collection(
            $client->locations()->withCount('departments')
                ->when($request->boolean('active_only'), fn ($query) => $query->where('active', true))
                ->orderBy('name')->paginate($filters['per_page'] ?? 25)
        );
    }

    public function show(Request $request, int $customer, int $location): CustomerLocationResource
    {
        $client = $request->attributes->get('nexa_tenant')->customers()->findOrFail($customer);
        return new CustomerLocationResource($client->locations()->withCount('departments')->findOrFail($location));
    }

    public function store(CustomerLocationRequest $request, int $customer): JsonResponse
    {
        $model = $this->organization->createLocation(
            $request->attributes->get('nexa_tenant'), $request->user(), $customer, $request->validated()
        );
        return (new CustomerLocationResource($model->loadCount('departments')))->response()->setStatusCode(201);
    }

    public function update(CustomerLocationRequest $request, int $customer, int $location): CustomerLocationResource
    {
        return new CustomerLocationResource($this->organization->updateLocation(
            $request->attributes->get('nexa_tenant'), $request->user(), $customer, $location, $request->validated()
        ));
    }

    public function destroy(Request $request, int $customer, int $location): JsonResponse
    {
        $this->organization->deactivateLocation(
            $request->attributes->get('nexa_tenant'), $request->user(), $customer, $location
        );
        return response()->json(null, 204);
    }
}
