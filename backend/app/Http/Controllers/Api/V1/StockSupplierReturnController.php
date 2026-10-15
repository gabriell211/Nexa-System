<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StockSupplierReturnRequest;
use App\Http\Resources\StockSupplierReturnResource;
use App\Models\StockSupplierReturn;
use App\Services\CustomerPortfolio;
use App\Services\StockSupplierReturnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

final class StockSupplierReturnController extends Controller
{
    public function __construct(
        private readonly CustomerPortfolio $portfolio,
        private readonly StockSupplierReturnService $returns,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->portfolio->assertFull($request);
        $filters = $request->validate([
            'purchase_order_id' => ['sometimes','integer','min:1'],
            'status' => ['sometimes', Rule::in(['pending','completed','cancelled'])],
            'page' => ['sometimes','integer','min:1'],
            'per_page' => ['sometimes','integer','between:1,100'],
        ]);
        return StockSupplierReturnResource::collection(
            StockSupplierReturn::query()
                ->where('tenant_id', $request->attributes->get('nexa_tenant')->id)
                ->when(isset($filters['purchase_order_id']), static fn ($q) =>
                    $q->whereHas('purchaseMatch', static fn ($matches) =>
                        $matches->where('purchase_order_id', $filters['purchase_order_id'])))
                ->when(isset($filters['status']), static fn ($q) => $q->where('status', $filters['status']))
                ->with(['creator:id,name','reviewer:id,name','purchaseMatch.receipt'])
                ->orderByDesc('id')->paginate($filters['per_page'] ?? 25)
        );
    }

    public function show(Request $request, int $return): StockSupplierReturnResource
    {
        $this->portfolio->assertFull($request);
        return new StockSupplierReturnResource(
            $this->returns->find((int) $request->attributes->get('nexa_tenant')->id, $return)
        );
    }

    public function store(StockSupplierReturnRequest $request): JsonResponse
    {
        $this->portfolio->assertFull($request);
        [$return, $created] = $this->returns->create(
            $request->attributes->get('nexa_tenant'), $request->user(), $request->validated()
        );
        return (new StockSupplierReturnResource($return))->response()->setStatusCode($created ? 201 : 200);
    }

    public function approve(Request $request, int $return): StockSupplierReturnResource
    {
        $this->portfolio->assertFull($request);
        $data = $request->validate([
            'expected_version' => ['required','integer','min:1'],
            'review_note' => ['sometimes','nullable','string','max:500'],
        ]);
        return new StockSupplierReturnResource($this->returns->approve(
            $request->attributes->get('nexa_tenant'), $request->user(), $return, $data
        ));
    }

    public function cancel(Request $request, int $return): StockSupplierReturnResource
    {
        $this->portfolio->assertFull($request);
        $data = $request->validate([
            'expected_version' => ['required','integer','min:1'],
            'reason' => ['required','string','min:5','max:500'],
        ]);
        return new StockSupplierReturnResource($this->returns->cancel(
            $request->attributes->get('nexa_tenant'), $request->user(), $return, $data,
            (string) $request->attributes->get('nexa_role')
        ));
    }
}
