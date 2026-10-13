<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StockItemRequest;
use App\Http\Resources\StockItemResource;
use App\Models\StockItem;
use App\Services\CustomerPortfolio;
use App\Services\InventoryCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

final class StockItemController extends Controller
{
    public function __construct(
        private readonly InventoryCatalogService $catalog,
        private readonly CustomerPortfolio $portfolio,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->portfolio->assertFull($request);
        $data = $request->validate([
            'page' => ['sometimes','integer','min:1'],
            'per_page' => ['sometimes','integer','between:1,100'],
        ]);
        return StockItemResource::collection(
            StockItem::query()->where('tenant_id', $request->attributes->get('nexa_tenant')->id)
                ->orderBy('sku')->paginate($data['per_page'] ?? 25)
        );
    }

    public function store(StockItemRequest $request): JsonResponse
    {
        $this->portfolio->assertFull($request);
        $item = $this->catalog->createItem(
            $request->attributes->get('nexa_tenant'), $request->user(), $request->validated()
        );
        return (new StockItemResource($item))->response()->setStatusCode(201);
    }
}
