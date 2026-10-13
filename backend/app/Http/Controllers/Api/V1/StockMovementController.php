<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StockMovementRequest;
use App\Http\Resources\StockMovementResource;
use App\Models\StockMovement;
use App\Services\CustomerPortfolio;
use App\Services\InventoryLedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

final class StockMovementController extends Controller
{
    public function __construct(
        private readonly InventoryLedgerService $ledger,
        private readonly CustomerPortfolio $portfolio,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->portfolio->assertFull($request);
        $data = $request->validate([
            'page' => ['sometimes','integer','min:1'],
            'per_page' => ['sometimes','integer','between:1,100'],
        ]);
        return StockMovementResource::collection(
            StockMovement::query()->where('tenant_id', $request->attributes->get('nexa_tenant')->id)
                ->with(['item','source','destination','actor:id,name'])
                ->orderByDesc('id')->paginate($data['per_page'] ?? 25)
        );
    }

    public function store(StockMovementRequest $request): JsonResponse
    {
        $this->portfolio->assertFull($request);
        $movement = $this->ledger->move(
            $request->attributes->get('nexa_tenant'), $request->user(), $request->validated()
        );
        return (new StockMovementResource($movement))->response()->setStatusCode(201);
    }
}
