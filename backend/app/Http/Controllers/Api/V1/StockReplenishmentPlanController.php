<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\StockReplenishmentPlanResource;
use App\Models\StockReplenishmentPlan;
use App\Services\CustomerPortfolio;
use App\Services\StockReplenishmentPlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

final class StockReplenishmentPlanController extends Controller
{
    public function __construct(
        private readonly CustomerPortfolio $portfolio,
        private readonly StockReplenishmentPlanService $plans,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->portfolio->assertFull($request);
        $filter = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'status' => ['sometimes', Rule::in(['draft', 'approved', 'cancelled'])],
            'responsible_user_id' => ['sometimes', 'integer', 'min:1'],
            'due_before' => ['sometimes', 'date_format:Y-m-d'],
        ]);
        return StockReplenishmentPlanResource::collection(
            StockReplenishmentPlan::query()
                ->where('tenant_id', $request->attributes->get('nexa_tenant')->id)
                ->when(isset($filter['status']), fn ($q) => $q->where('status', $filter['status']))
                ->when(isset($filter['responsible_user_id']), fn ($q) => $q->where('responsible_user_id', $filter['responsible_user_id']))
                ->when(isset($filter['due_before']), fn ($q) => $q->whereDate('due_on', '<=', $filter['due_before']))
                ->with(['responsible:id,name', 'createdBy:id,name', 'approvedBy:id,name', 'cancelledBy:id,name'])
                ->withCount('items')->orderBy('due_on')->orderBy('id')
                ->paginate($filter['per_page'] ?? 25)
        );
    }

    public function show(Request $request, int $plan): StockReplenishmentPlanResource
    {
        $this->portfolio->assertFull($request);
        return new StockReplenishmentPlanResource($this->plans->find(
            (int) $request->attributes->get('nexa_tenant')->id, $plan
        ));
    }

    public function store(Request $request): JsonResponse
    {
        $this->portfolio->assertFull($request);
        $data = $request->validate([
            'request_id' => ['required', 'uuid'],
            'due_on' => ['required', 'date_format:Y-m-d'],
            'responsible_user_id' => ['required', 'integer', 'min:1'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'policy_ids' => ['present', 'array', 'max:100'],
            'policy_ids.*' => ['integer', 'min:1', 'distinct'],
        ]);
        [$plan, $created] = $this->plans->create(
            $request->attributes->get('nexa_tenant'), $request->user(), $data
        );
        return (new StockReplenishmentPlanResource($plan))->response()->setStatusCode($created ? 201 : 200);
    }

    public function update(Request $request, int $plan): StockReplenishmentPlanResource
    {
        $this->portfolio->assertFull($request);
        $data = $request->validate([
            'expected_version' => ['required', 'integer', 'min:1'],
            'due_on' => ['sometimes', 'date_format:Y-m-d'],
            'responsible_user_id' => ['sometimes', 'integer', 'min:1'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'items' => ['sometimes', 'array', 'max:100'],
            'items.*.id' => ['sometimes', 'integer', 'min:1'],
            'items.*.item_id' => ['required', 'integer', 'min:1'],
            'items.*.location_id' => ['required', 'integer', 'min:1'],
            'items.*.quantity' => ['required', 'integer', 'between:1,1000000'],
            'items.*.safety_item' => ['required', 'boolean'],
            'items.*.note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);
        return new StockReplenishmentPlanResource($this->plans->update(
            $request->attributes->get('nexa_tenant'), $request->user(), $plan, $data
        ));
    }

    public function approve(Request $request, int $plan): StockReplenishmentPlanResource
    {
        $this->portfolio->assertFull($request);
        $data = $request->validate([
            'expected_version' => ['required', 'integer', 'min:1'],
            'review_note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);
        return new StockReplenishmentPlanResource($this->plans->approve(
            $request->attributes->get('nexa_tenant'), $request->user(), $plan, $data
        ));
    }

    public function cancel(Request $request, int $plan): StockReplenishmentPlanResource
    {
        $this->portfolio->assertFull($request);
        $data = $request->validate([
            'expected_version' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);
        return new StockReplenishmentPlanResource($this->plans->cancel(
            $request->attributes->get('nexa_tenant'), $request->user(), $plan, $data
        ));
    }
}
