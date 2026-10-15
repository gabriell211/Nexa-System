<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StockPlanShipmentRequest;
use App\Http\Resources\StockShipmentResource;
use App\Services\CustomerPortfolio;
use App\Services\StockPlanShipmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

final class StockPlanShipmentController extends Controller
{
    public function __construct(
        private readonly CustomerPortfolio $portfolio,
        private readonly StockPlanShipmentService $fulfillment,
    ) {}

    public function store(StockPlanShipmentRequest $request, int $plan, int $line): JsonResponse
    {
        $this->portfolio->assertFull($request);
        [$shipment, $created] = $this->fulfillment->reserve(
            $request->attributes->get('nexa_tenant'), $request->user(), $plan, $line,
            $request->validated()
        );
        return (new StockShipmentResource($shipment))
            ->response()->setStatusCode($created ? 201 : 200);
    }
}
