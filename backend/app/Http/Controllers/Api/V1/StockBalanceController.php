<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\StockBalanceResource;
use App\Models\StockBalance;
use App\Services\CustomerPortfolio;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

final class StockBalanceController extends Controller
{
    public function __construct(private readonly CustomerPortfolio $portfolio) {}

    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $this->portfolio->assertFull($request);
        $data = $request->validate([
            'page' => ['sometimes','integer','min:1'],
            'per_page' => ['sometimes','integer','between:1,100'],
            'item_id' => ['sometimes','integer','min:1'],
            'location_id' => ['sometimes','integer','min:1'],
        ]);
        return StockBalanceResource::collection(
            StockBalance::query()->where('tenant_id', $request->attributes->get('nexa_tenant')->id)
                ->with(['item','location'])
                ->when(isset($data['item_id']), fn ($q) => $q->where('item_id', $data['item_id']))
                ->when(isset($data['location_id']), fn ($q) => $q->where('location_id', $data['location_id']))
                ->orderBy('location_id')->orderBy('item_id')
                ->paginate($data['per_page'] ?? 25)
        );
    }
}
