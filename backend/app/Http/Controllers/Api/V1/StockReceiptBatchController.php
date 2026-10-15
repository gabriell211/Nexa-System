<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StockReceiptBatchRequest;
use App\Http\Resources\StockReceiptBatchResource;
use App\Services\CustomerPortfolio;
use App\Services\StockReceiptBatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class StockReceiptBatchController extends Controller
{
    public function __construct(
        private readonly StockReceiptBatchService $batches,
        private readonly CustomerPortfolio $portfolio,
    ) {}

    public function store(StockReceiptBatchRequest $request): JsonResponse
    {
        $this->portfolio->assertFull($request);
        [$batch, $created] = $this->batches->create(
            $request->attributes->get('nexa_tenant'), $request->user(), $request->validated()
        );
        return (new StockReceiptBatchResource($batch))
            ->response()->setStatusCode($created ? 201 : 200);
    }

    public function show(Request $request, int $batch): StockReceiptBatchResource
    {
        $this->portfolio->assertFull($request);
        return new StockReceiptBatchResource($this->batches->find(
            (int) $request->attributes->get('nexa_tenant')->id, $batch
        ));
    }
}
