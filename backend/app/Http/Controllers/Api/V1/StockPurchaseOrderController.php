<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StockPurchaseOrderRequest;
use App\Http\Resources\StockPurchaseOrderResource;
use App\Models\StockPurchaseOrder;
use App\Services\CustomerPortfolio;
use App\Services\StockPurchaseOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

final class StockPurchaseOrderController extends Controller
{
    public function __construct(
        private readonly CustomerPortfolio $portfolio,
        private readonly StockPurchaseOrderService $orders,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->portfolio->assertFull($request);
        $filter = $request->validate([
            'status' => ['sometimes', Rule::in(['draft','approved','cancelled'])],
            'plan_id' => ['sometimes','integer','min:1'],
            'supplier_id' => ['sometimes','integer','min:1'],
            'page' => ['sometimes','integer','min:1'],
            'per_page' => ['sometimes','integer','between:1,100'],
        ]);
        return StockPurchaseOrderResource::collection(
            StockPurchaseOrder::query()
                ->where('tenant_id', $request->attributes->get('nexa_tenant')->id)
                ->when(isset($filter['status']), static fn ($q) => $q->where('status', $filter['status']))
                ->when(isset($filter['plan_id']), static fn ($q) => $q->where('plan_id', $filter['plan_id']))
                ->when(isset($filter['supplier_id']), static fn ($q) => $q->where('supplier_id', $filter['supplier_id']))
                ->with([
                    'supplier:id,code,name,active','creator:id,name','approver:id,name',
                    'lines' => static fn ($q) => $q->orderBy('id'),
                    'lines.planLine.item:id,sku,name,unit','lines.planLine.location:id,name',
                ])->withCount('lines')->orderByDesc('id')
                ->paginate($filter['per_page'] ?? 25)
        );
    }

    public function show(Request $request, int $order): StockPurchaseOrderResource
    {
        $this->portfolio->assertFull($request);
        return new StockPurchaseOrderResource($this->orders->find(
            (int) $request->attributes->get('nexa_tenant')->id, $order
        ));
    }

    public function store(StockPurchaseOrderRequest $request): JsonResponse
    {
        $this->portfolio->assertFull($request);
        [$order, $created] = $this->orders->create(
            $request->attributes->get('nexa_tenant'), $request->user(), $request->validated()
        );
        return (new StockPurchaseOrderResource($order))->response()
            ->setStatusCode($created ? 201 : 200);
    }

    public function approve(Request $request, int $order): StockPurchaseOrderResource
    {
        $this->portfolio->assertFull($request);
        $data = $request->validate([
            'expected_version' => ['required','integer','min:1'],
            'review_note' => ['sometimes','nullable','string','max:500'],
        ]);
        return new StockPurchaseOrderResource($this->orders->approve(
            $request->attributes->get('nexa_tenant'), $request->user(), $order, $data
        ));
    }

    public function cancel(Request $request, int $order): StockPurchaseOrderResource
    {
        $this->portfolio->assertFull($request);
        $data = $request->validate([
            'expected_version' => ['required','integer','min:1'],
            'reason' => ['required','string','min:5','max:500'],
        ]);
        $existing = $this->orders->find(
            (int) $request->attributes->get('nexa_tenant')->id, $order
        );
        $manager = in_array($request->attributes->get('nexa_role'), ['owner','admin','manager'], true);
        if (!$manager && ($existing->status !== 'draft'
            || (int) $existing->created_by_user_id !== (int) $request->user()->id)) {
            abort(403, 'Somente a gestão cancela compras aprovadas.');
        }
        return new StockPurchaseOrderResource($this->orders->cancel(
            $request->attributes->get('nexa_tenant'), $request->user(), $order, $data
        ));
    }
}
