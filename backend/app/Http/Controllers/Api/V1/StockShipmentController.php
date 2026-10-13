<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StockShipmentCloseRequest;
use App\Http\Requests\StockShipmentEventRequest;
use App\Http\Requests\StockShipmentRequest;
use App\Http\Resources\StockShipmentResource;
use App\Models\StockShipment;
use App\Services\CustomerPortfolio;
use App\Services\StockShipmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

final class StockShipmentController extends Controller
{
    public function __construct(
        private readonly StockShipmentService $shipments,
        private readonly CustomerPortfolio $portfolio,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->portfolio->assertFull($request);
        $filters = $request->validate([
            'per_page'=>['sometimes','integer','between:1,100'],
            'page'=>['sometimes','integer','min:1'],
            'status'=>['sometimes','string','max:32'],
        ]);
        return StockShipmentResource::collection(
            StockShipment::query()->where('tenant_id', $request->attributes->get('nexa_tenant')->id)
                ->when(isset($filters['status']), static fn ($q) => $q->where('status', $filters['status']))
                ->with(['item','source','destination','lotTransit'])->orderByDesc('id')
                ->paginate($filters['per_page'] ?? 25)
        );
    }

    public function show(Request $request, int $shipment): StockShipmentResource
    {
        $this->portfolio->assertFull($request);
        $record = StockShipment::query()
            ->where('tenant_id', $request->attributes->get('nexa_tenant')->id)
            ->with(['item','source','destination','lotTransit','events'=>static fn ($query) =>
                $query->with('actor:id,name')->orderBy('id')])
            ->findOrFail($shipment);
        return new StockShipmentResource($record);
    }

    public function store(StockShipmentRequest $request): JsonResponse
    {
        $this->portfolio->assertFull($request);
        $record = $this->shipments->reserve(
            $request->attributes->get('nexa_tenant'), $request->user(), $request->validated()
        );
        return (new StockShipmentResource($record))->response()->setStatusCode(201);
    }

    public function dispatch(StockShipmentEventRequest $request, int $shipment): StockShipmentResource
    {
        $this->portfolio->assertFull($request);
        return new StockShipmentResource($this->shipments->dispatch(
            $request->attributes->get('nexa_tenant'), $request->user(), $shipment, $request->validated()
        ));
    }

    public function receive(StockShipmentEventRequest $request, int $shipment): StockShipmentResource
    {
        $this->portfolio->assertFull($request);
        return new StockShipmentResource($this->shipments->receive(
            $request->attributes->get('nexa_tenant'), $request->user(), $shipment, $request->validated()
        ));
    }

    public function close(StockShipmentCloseRequest $request, int $shipment): StockShipmentResource
    {
        $this->portfolio->assertFull($request);
        // Writing off missing inventory is a supervisory action, not routine
        // warehouse dispatch/receipt. Do not grant it to warehouse operators.
        if (!in_array($request->attributes->get('nexa_role'), ['owner', 'admin', 'manager'], true)) {
            abort(403, 'Encerramento com perdas/cancelamento exige supervisão.');
        }
        return new StockShipmentResource($this->shipments->close(
            $request->attributes->get('nexa_tenant'), $request->user(), $shipment, $request->validated()
        ));
    }
}
