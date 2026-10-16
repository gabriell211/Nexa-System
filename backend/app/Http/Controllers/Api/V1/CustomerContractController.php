<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\CustomerContractRequest;
use App\Http\Resources\CustomerContractResource;
use App\Models\CustomerContract;
use App\Services\ContractCycle;
use App\Services\CustomerContractService;
use App\Services\CustomerPortfolio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

final class CustomerContractController extends Controller
{
    public function __construct(
        private readonly CustomerContractService $contracts,
        private readonly CustomerPortfolio $portfolio,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'customer_id' => ['sometimes', 'integer', 'min:1'],
            'active_only' => ['sometimes', 'in:0,1,true,false'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'ending_before' => ['sometimes', 'date_format:Y-m-d'],
        ]);
        $tenant = $request->attributes->get('nexa_tenant');
        $query = CustomerContract::query()->where('tenant_id', $tenant->id)
            ->with('customer');
        if (!$this->portfolio->hasFullAccess($request)) {
            $query->whereIn('customer_id', $this->portfolio->assignedIds($request));
        }
        if (isset($filters['customer_id'])) {
            $query->where('customer_id', $filters['customer_id']);
        }
        if ($request->boolean('active_only')) {
            $query->where('active', true);
        }
        if (isset($filters['ending_before'])) {
            $query->whereNotNull('ends_on')->where('ends_on', '<=', $filters['ending_before']);
        }
        $q = trim($filters['q'] ?? '');
        if ($q !== '') {
            $like = '%'.mb_strtolower($q).'%';
            $query->where(static fn ($builder) => $builder
                ->whereRaw('LOWER(title) LIKE ?', [$like])
                ->orWhereRaw('LOWER(document_number) LIKE ?', [$like]));
        }
        return CustomerContractResource::collection(
            $query->orderByDesc('id')->paginate($filters['per_page'] ?? 25)
        );
    }

    public function store(CustomerContractRequest $request): JsonResponse
    {
        $data = $request->validated();
        $this->portfolio->assertCustomer($request, (int) $data['customer_id']);
        $contract = $this->contracts->create(
            $request->attributes->get('nexa_tenant'), $request->user(), $data
        );
        return (new CustomerContractResource($contract))->response()->setStatusCode(201);
    }

    public function show(Request $request, int $contract): CustomerContractResource
    {
        return new CustomerContractResource($this->visible($request, $contract));
    }

    public function update(CustomerContractRequest $request, int $contract): CustomerContractResource
    {
        $this->visible($request, $contract);
        return new CustomerContractResource($this->contracts->update(
            $request->attributes->get('nexa_tenant'), $request->user(),
            $contract, $request->validated()
        ));
    }

    public function cycle(Request $request, int $contract): JsonResponse
    {
        $data = $request->validate(['as_of' => ['sometimes', 'date_format:Y-m-d']]);
        $model = $this->visible($request, $contract);
        $asOf = $data['as_of'] ?? now($model->billing_timezone)->toDateString();
        return response()->json([
            'contract_id' => $model->id,
            'as_of' => $asOf,
            'cycle' => ContractCycle::at(
                $model->billing_starts_on->toDateString(), $asOf,
                $model->ends_on?->toDateString()
            ),
        ]);
    }

    private function visible(Request $request, int $id): CustomerContract
    {
        $model = CustomerContract::query()->where('tenant_id',
            $request->attributes->get('nexa_tenant')->id)->with('customer')->findOrFail($id);
        $this->portfolio->assertCustomer($request, $model->customer_id);
        return $model;
    }
}
