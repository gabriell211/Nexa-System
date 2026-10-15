<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\StockReorderPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** One calculation for the live needs list and persisted plan snapshots. */
final class StockReorderAvailability
{
    public function availableExpression(): string
    {
        $net = '(COALESCE(balances.quantity, 0) - COALESCE(reservations.reserved_quantity, 0)'
            .' - COALESCE(expired.expired_quantity, 0))';

        // Reservations are not attributed to lots. Subtracting both a later
        // expiry and its possible reservation is a conservative lower bound.
        return "(CASE WHEN {$net} > 0 THEN {$net} ELSE 0 END)";
    }

    public function query(int $tenantId): Builder
    {
        $today = now('UTC')->toDateString();
        $reservations = DB::table('stock_shipments')
            ->where('tenant_id', $tenantId)
            ->select('item_id', 'source_location_id')
            ->selectRaw('SUM(quantity - shipped_quantity - cancelled_quantity) AS reserved_quantity')
            ->groupBy('item_id', 'source_location_id');
        $expired = DB::table('stock_lot_balances as lot_balances')
            ->join('stock_lots as lots', static function ($join): void {
                $join->on('lots.id', '=', 'lot_balances.lot_id')
                    ->on('lots.tenant_id', '=', 'lot_balances.tenant_id');
            })
            ->where('lot_balances.tenant_id', $tenantId)
            ->whereNotNull('lots.expires_on')->where('lots.expires_on', '<', $today)
            ->select('lots.item_id', 'lot_balances.location_id')
            ->selectRaw('SUM(lot_balances.quantity) AS expired_quantity')
            ->groupBy('lots.item_id', 'lot_balances.location_id');

        return StockReorderPolicy::query()->from('stock_reorder_policies as policies')
            ->where('policies.tenant_id', $tenantId)
            ->join('stock_items as items', static function ($join): void {
                $join->on('items.id', '=', 'policies.item_id')
                    ->on('items.tenant_id', '=', 'policies.tenant_id');
            })
            ->join('stock_locations as locations', static function ($join): void {
                $join->on('locations.id', '=', 'policies.location_id')
                    ->on('locations.tenant_id', '=', 'policies.tenant_id');
            })
            ->leftJoin('stock_balances as balances', static function ($join): void {
                $join->on('balances.tenant_id', '=', 'policies.tenant_id')
                    ->on('balances.item_id', '=', 'policies.item_id')
                    ->on('balances.location_id', '=', 'policies.location_id');
            })
            ->leftJoinSub($reservations, 'reservations', static function ($join): void {
                $join->on('reservations.item_id', '=', 'policies.item_id')
                    ->on('reservations.source_location_id', '=', 'policies.location_id');
            })
            ->leftJoinSub($expired, 'expired', static function ($join): void {
                $join->on('expired.item_id', '=', 'policies.item_id')
                    ->on('expired.location_id', '=', 'policies.location_id');
            })
            ->select('policies.*')
            ->selectRaw('COALESCE(balances.quantity, 0) AS physical_quantity')
            ->selectRaw('COALESCE(reservations.reserved_quantity, 0) AS reserved_quantity')
            ->selectRaw('COALESCE(expired.expired_quantity, 0) AS expired_quantity');
    }

    public function snapshot(int $tenantId, int $policyId): ?array
    {
        $policy = $this->query($tenantId)->with(['item', 'location'])
            ->where('policies.id', $policyId)->first();
        if ($policy === null) {
            return null;
        }
        $available = max(0, (int) $policy->physical_quantity - (int) $policy->reserved_quantity
            - (int) $policy->expired_quantity);
        $active = (bool) $policy->item->active && (bool) $policy->location->active;
        return [
            'policy' => $policy,
            'available_quantity' => $available,
            'suggested_quantity' => $active ? max(0, (int) $policy->target_quantity - $available) : 0,
            'needs_replenishment' => $active && $available <= (int) $policy->minimum_quantity,
        ];
    }
}
