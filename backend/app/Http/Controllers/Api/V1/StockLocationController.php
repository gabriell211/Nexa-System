<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StockLocationRequest;
use App\Http\Resources\StockLocationResource;
use App\Models\StockLocation;
use App\Services\CustomerPortfolio;
use App\Services\InventoryCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

final class StockLocationController extends Controller
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
        return StockLocationResource::collection(
            StockLocation::query()->where('tenant_id', $request->attributes->get('nexa_tenant')->id)
                ->orderBy('name')->paginate($data['per_page'] ?? 25)
        );
    }

    public function store(StockLocationRequest $request): JsonResponse
    {
        $this->portfolio->assertFull($request);
        $location = $this->catalog->createLocation(
            $request->attributes->get('nexa_tenant'), $request->user(), $request->validated()
        );
        return (new StockLocationResource($location))->response()->setStatusCode(201);
    }
}
