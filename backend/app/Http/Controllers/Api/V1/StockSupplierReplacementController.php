<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StockSupplierReplacementCaseRequest;
use App\Http\Requests\StockSupplierReplacementEventRequest;
use App\Http\Requests\StockSupplierReplacementReceiptRequest;
use App\Http\Resources\StockSupplierReplacementCaseResource;
use App\Models\StockSupplierReplacementCase;
use App\Services\CustomerPortfolio;
use App\Services\StockSupplierReplacementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

final class StockSupplierReplacementController extends Controller
{
    public function __construct(
        private readonly CustomerPortfolio $portfolio,
        private readonly StockSupplierReplacementService $service,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->portfolio->assertFull($request);
        $filter = $request->validate([
            'purchase_order_id'=>['sometimes','integer','min:1'],
            'status'=>['sometimes',Rule::in(['awaiting_supplier','supplier_confirmed','closed_unresolved','receipt_documented'])],
            'overdue_only'=>['sometimes','in:0,1'],
            'page'=>['sometimes','integer','min:1'],
            'per_page'=>['sometimes','integer','between:1,100'],
        ]);
        return StockSupplierReplacementCaseResource::collection(
            StockSupplierReplacementCase::query()
                ->where('tenant_id',$request->attributes->get('nexa_tenant')->id)
                ->when(isset($filter['purchase_order_id']), static fn ($q) =>
                    $q->whereHas('supplierReturn.purchaseMatch', static fn ($matches) =>
                        $matches->where('purchase_order_id',$filter['purchase_order_id'])))
                ->when(isset($filter['status']), static fn ($q) => $q->where('status',$filter['status']))
                ->when(($filter['overdue_only'] ?? null)==='1', static fn ($q) => $q
                    ->where('status','awaiting_supplier')->whereDate('due_on','<',today()))
                ->with(['assignee:id,name','creator:id,name','supplierReturn.purchaseMatch'])
                ->withSum('replacementReceipts as documented_quantity', 'quantity')
                ->orderBy('due_on')->orderBy('id')
                ->paginate($filter['per_page'] ?? 25)
        );
    }

    public function show(Request $request, int $case): StockSupplierReplacementCaseResource
    {
        $this->portfolio->assertFull($request);
        return new StockSupplierReplacementCaseResource($this->service->find(
            (int)$request->attributes->get('nexa_tenant')->id, $case
        ));
    }

    public function store(StockSupplierReplacementCaseRequest $request): JsonResponse
    {
        $this->portfolio->assertFull($request);
        [$case,$created] = $this->service->create(
            $request->attributes->get('nexa_tenant'),$request->user(),$request->validated()
        );
        return (new StockSupplierReplacementCaseResource($case))->response()
            ->setStatusCode($created ? 201 : 200);
    }

    public function event(StockSupplierReplacementEventRequest $request, int $case): JsonResponse
    {
        $this->portfolio->assertFull($request);
        [$updated,$created] = $this->service->record(
            $request->attributes->get('nexa_tenant'),$request->user(),$case,
            (string)$request->attributes->get('nexa_role'),$request->validated()
        );
        return (new StockSupplierReplacementCaseResource($updated))->response()
            ->setStatusCode($created ? 201 : 200);
    }

    public function receipt(StockSupplierReplacementReceiptRequest $request, int $case): JsonResponse
    {
        $this->portfolio->assertFull($request);
        [$updated, $created] = $this->service->linkReceipt(
            $request->attributes->get('nexa_tenant'), $request->user(), $case,
            $request->validated()
        );
        return (new StockSupplierReplacementCaseResource($updated))->response()
            ->setStatusCode($created ? 201 : 200);
    }
}
