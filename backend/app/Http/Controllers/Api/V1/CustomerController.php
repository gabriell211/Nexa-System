<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\CustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Services\CustomerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

final class CustomerController extends Controller
{
    public function __construct(private readonly CustomerService $customers) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $tenant = $request->attributes->get('nexa_tenant');

        return CustomerResource::collection(
            $tenant->customers()
                ->when($request->boolean('active_only'), fn ($query) => $query->where('active', true))
                ->orderBy('name')
                ->paginate(25)
        );
    }

    public function store(CustomerRequest $request): CustomerResource
    {
        $customer = $this->customers->create(
            $request->attributes->get('nexa_tenant'),
            $request->user(),
            $request->validated()
        );

        return new CustomerResource($customer);
    }

    public function show(Request $request, int $customer): CustomerResource
    {
        return new CustomerResource($request->attributes->get('nexa_tenant')->customers()->findOrFail($customer));
    }

    public function update(CustomerRequest $request, int $customer): CustomerResource
    {
        return new CustomerResource($this->customers->update(
            $request->attributes->get('nexa_tenant'),
            $request->user(),
            $customer,
            $request->validated()
        ));
    }

    public function destroy(Request $request, int $customer): JsonResponse
    {
        $this->customers->deactivate($request->attributes->get('nexa_tenant'), $request->user(), $customer);

        return response()->json(null, 204);
    }
}
