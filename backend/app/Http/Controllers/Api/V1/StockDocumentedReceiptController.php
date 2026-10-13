<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StockDocumentedReceiptRequest;
use App\Http\Resources\StockReceiptResource;
use App\Models\StockReceiptDetail;
use App\Services\CustomerPortfolio;
use App\Services\StockDocumentedReceiptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

final class StockDocumentedReceiptController extends Controller
{
    public function __construct(
        private readonly StockDocumentedReceiptService $receipts,
        private readonly CustomerPortfolio $portfolio,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->portfolio->assertFull($request);
        $data=$request->validate([
            'page'=>['sometimes','integer','min:1'],
            'per_page'=>['sometimes','integer','between:1,100'],
        ]);
        return StockReceiptResource::collection(
            StockReceiptDetail::query()
                ->where('tenant_id',$request->attributes->get('nexa_tenant')->id)
                ->with(['supplier','movement.item','movement.destination'])
                ->orderByDesc('id')->paginate($data['per_page']??25)
        );
    }

    public function store(StockDocumentedReceiptRequest $request): JsonResponse
    {
        $this->portfolio->assertFull($request);
        $detail=$this->receipts->create(
            $request->attributes->get('nexa_tenant'),$request->user(),$request->validated()
        );
        return (new StockReceiptResource($detail))->response()->setStatusCode(201);
    }
}
