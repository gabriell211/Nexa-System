<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StockPurchaseReceiptMatchRequest;
use App\Http\Resources\StockPurchaseOrderResource;
use App\Services\CustomerPortfolio;
use App\Services\StockPurchaseOrderService;
use App\Services\StockPurchaseReceiptMatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

final class StockPurchaseReceiptMatchController extends Controller
{
    public function __construct(
        private readonly CustomerPortfolio $portfolio,
        private readonly StockPurchaseReceiptMatchService $matches,
        private readonly StockPurchaseOrderService $orders,
    ) {}

    public function store(StockPurchaseReceiptMatchRequest $request, int $order): JsonResponse
    {
        $this->portfolio->assertFull($request);
        [, $created] = $this->matches->attach(
            $request->attributes->get('nexa_tenant'), $request->user(), $order,
            $request->validated()
        );
        return (new StockPurchaseOrderResource($this->orders->find(
            (int) $request->attributes->get('nexa_tenant')->id, $order
        )))->response()->setStatusCode($created ? 201 : 200);
    }
}
