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
            'q'=>['sometimes','nullable','string','max:100'],
            'supplier_id'=>['sometimes','integer','min:1'],
            'item_id'=>['sometimes','integer','min:1'],
            'destination_location_id'=>['sometimes','integer','min:1'],
            'unmatched_only'=>['sometimes','in:0,1'],
        ]);
        return StockReceiptResource::collection(
            StockReceiptDetail::query()
                ->where('tenant_id',$request->attributes->get('nexa_tenant')->id)
                ->when(isset($data['supplier_id']), static fn ($query) => $query->where('supplier_id', $data['supplier_id']))
                ->when(isset($data['item_id']), static fn ($query) => $query->whereHas('movement',
                    static fn ($movement) => $movement->where('item_id', $data['item_id'])))
                ->when(isset($data['destination_location_id']), static fn ($query) => $query->whereHas('movement',
                    static fn ($movement) => $movement->where('destination_location_id', $data['destination_location_id'])))
                ->when(($data['unmatched_only'] ?? null) === '1', static fn ($query) => $query->whereDoesntHave('purchaseMatch'))
                ->when(trim($data['q'] ?? '') !== '', static fn ($query) => $query->where(
                    static fn ($match) => $match->whereRaw('LOWER(invoice_reference) LIKE ?', ['%'.mb_strtolower(trim($data['q'])).'%'])
                        ->orWhereRaw('LOWER(lot_number) LIKE ?', ['%'.mb_strtolower(trim($data['q'])).'%'])
                ))
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
