<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\StockLotBalance;
use App\Services\CustomerPortfolio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class StockLotController extends Controller
{
    public function __construct(private readonly CustomerPortfolio $portfolio) {}

    public function __invoke(Request $request): JsonResponse
    {
        $this->portfolio->assertFull($request);
        $filters = $request->validate([
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'item_id' => ['sometimes', 'integer', 'min:1'],
            'location_id' => ['sometimes', 'integer', 'min:1'],
            'include_zero' => ['sometimes', 'boolean'],
        ]);
        $tenant = $request->attributes->get('nexa_tenant');
        $today = now('UTC')->toDateString();
        $page = StockLotBalance::query()
            ->where('tenant_id', $tenant->id)
            ->when(empty($filters['include_zero']), fn ($q) => $q->where('quantity', '>', 0))
            ->when(isset($filters['location_id']), fn ($q) => $q->where('location_id', $filters['location_id']))
            ->when(isset($filters['item_id']), fn ($q) =>
                $q->whereHas('lot', fn ($lot) => $lot->where('item_id', $filters['item_id'])))
            ->with(['lot.item', 'location'])
            ->orderBy('id')->paginate($filters['per_page'] ?? 25);

        return response()->json($page->through(static function (StockLotBalance $position) use ($today): array {
            $lot = $position->lot;
            $expires = $lot->expires_on?->toDateString();
            return [
                'id' => $position->id,
                'lot_id' => $lot->id,
                'lot_number' => $lot->lot_number,
                'item_id' => $lot->item_id,
                'item_sku' => $lot->item->sku,
                'item_name' => $lot->item->name,
                'location_id' => $position->location_id,
                'location_name' => $position->location->name,
                'quantity' => $position->quantity,
                'unit_cost_cents' => $lot->unit_cost_cents,
                'expires_on' => $expires,
                'expired' => $expires !== null && $expires < $today,
                'receipt_detail_id' => $lot->receipt_detail_id,
            ];
        }));
    }
}
