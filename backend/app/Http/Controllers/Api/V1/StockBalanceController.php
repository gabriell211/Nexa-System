<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\StockBalanceResource;
use App\Models\StockBalance;
use App\Services\CustomerPortfolio;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

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
        $page = StockBalance::query()->where('tenant_id', $request->attributes->get('nexa_tenant')->id)
                ->with(['item','location'])
                ->when(isset($data['item_id']), fn ($q) => $q->where('item_id', $data['item_id']))
                ->when(isset($data['location_id']), fn ($q) => $q->where('location_id', $data['location_id']))
                ->orderBy('location_id')->orderBy('item_id')
                ->paginate($data['per_page'] ?? 25);

        // Aggregate only the current page positions; avoid an N+1 query per row.
        $rows = $page->getCollection();
        if ($rows->isNotEmpty()) {
            $reservations = DB::table('stock_shipments')
                ->where('tenant_id', $request->attributes->get('nexa_tenant')->id)
                ->whereIn('item_id', $rows->pluck('item_id')->unique()->all())
                ->whereIn('source_location_id', $rows->pluck('location_id')->unique()->all())
                ->select('item_id', 'source_location_id')
                ->selectRaw('SUM(quantity - shipped_quantity - cancelled_quantity) AS reserved')
                ->groupBy('item_id', 'source_location_id')->get()
                ->keyBy(static fn ($row): string => $row->item_id.':'.$row->source_location_id);
            $expiredPositions = DB::table('stock_lot_balances as balances')
                ->join('stock_lots as lots', 'lots.id', '=', 'balances.lot_id')
                ->where('balances.tenant_id', $request->attributes->get('nexa_tenant')->id)
                ->whereIn('lots.item_id', $rows->pluck('item_id')->unique()->all())
                ->whereIn('balances.location_id', $rows->pluck('location_id')->unique()->all())
                ->whereNotNull('lots.expires_on')
                ->where('lots.expires_on', '<', now('UTC')->toDateString())
                ->select('lots.item_id', 'balances.location_id')
                ->selectRaw('SUM(balances.quantity) AS expired')
                ->groupBy('lots.item_id', 'balances.location_id')->get()
                ->keyBy(static fn ($row): string => $row->item_id.':'.$row->location_id);

            foreach ($rows as $balance) {
                $key = $balance->item_id.':'.$balance->location_id;
                $reserved = (int) ($reservations->get($key)?->reserved ?? 0);
                $balance->setAttribute('reserved_quantity', $reserved);
                $expired = (int) ($expiredPositions->get($key)?->expired ?? 0);
                $balance->setAttribute('expired_quantity', $expired);
                $balance->setAttribute(
                    'available_quantity', max(0, (int) $balance->quantity - $reserved - $expired)
                );
            }
        }

        return StockBalanceResource::collection($page);
    }
}
