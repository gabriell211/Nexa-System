<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\StockReorderPolicyResource;
use App\Models\StockItem;
use App\Models\StockLocation;
use App\Models\StockReorderPolicy;
use App\Services\AuditWriter;
use App\Services\CustomerPortfolio;
use App\Services\StockReorderAvailability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class StockReorderPolicyController extends Controller
{
    public function __construct(
        private readonly CustomerPortfolio $portfolio,
        private readonly AuditWriter $audit,
        private readonly StockReorderAvailability $availability,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->portfolio->assertFull($request);
        $input = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'item_id' => ['sometimes', 'integer', 'min:1'],
            'location_id' => ['sometimes', 'integer', 'min:1'],
            'needs_only' => ['sometimes', 'in:0,1,true,false'],
            'status' => ['sometimes', 'in:critical,safety,low,ok,inactive'],
        ]);
        $tenantId = (int) $request->attributes->get('nexa_tenant')->id;
        $available = $this->availability->availableExpression();

        $query = $this->availability->query($tenantId)
            ->when(isset($input['item_id']), fn ($q) => $q->where('policies.item_id', $input['item_id']))
            ->when(isset($input['location_id']), fn ($q) => $q->where('policies.location_id', $input['location_id']));

        if ($request->boolean('needs_only', true)) {
            $query->where('items.active', true)->where('locations.active', true)
                ->whereRaw("{$available} <= policies.minimum_quantity");
        }
        if (isset($input['status'])) {
            match ($input['status']) {
                'inactive' => $query->where(fn ($q) => $q->where('items.active', false)
                    ->orWhere('locations.active', false)),
                'critical' => $query->where('items.active', true)->where('locations.active', true)
                    ->whereRaw("{$available} <= policies.critical_quantity"),
                'safety' => $query->where('items.active', true)->where('locations.active', true)
                    ->whereRaw("{$available} > policies.critical_quantity")
                    ->whereRaw("{$available} <= policies.safety_quantity"),
                'low' => $query->where('items.active', true)->where('locations.active', true)
                    ->whereRaw("{$available} > policies.safety_quantity")
                    ->whereRaw("{$available} <= policies.minimum_quantity"),
                'ok' => $query->where('items.active', true)->where('locations.active', true)
                    ->whereRaw("{$available} > policies.minimum_quantity"),
            };
        }

        return StockReorderPolicyResource::collection(
            $query->with(['item', 'location'])
                ->orderBy('policies.location_id')->orderBy('policies.item_id')
                ->paginate($input['per_page'] ?? 25)
        );
    }

    public function upsert(Request $request, int $item, int $location): JsonResponse
    {
        $this->portfolio->assertFull($request);
        $input = Validator::make([
            ...$request->all(), 'item_id' => $item, 'location_id' => $location,
        ], [
            'item_id' => ['required', 'integer', 'min:1'],
            'location_id' => ['required', 'integer', 'min:1'],
            'critical_quantity' => ['required', 'integer', 'between:0,1000000000'],
            'safety_quantity' => ['required', 'integer', 'between:0,1000000000'],
            'minimum_quantity' => ['required', 'integer', 'between:0,1000000000'],
            'target_quantity' => ['required', 'integer', 'between:0,1000000000'],
        ])->validate();
        if ((int) $input['critical_quantity'] > (int) $input['safety_quantity'] ||
            (int) $input['safety_quantity'] > (int) $input['minimum_quantity'] ||
            (int) $input['minimum_quantity'] > (int) $input['target_quantity']) {
            throw ValidationException::withMessages([
                'minimum_quantity' => 'Use crítico ≤ segurança ≤ mínimo ≤ alvo.',
            ]);
        }

        [$policy, $created] = DB::transaction(function () use ($request, $input): array {
            $tenant = $request->attributes->get('nexa_tenant');
            // Serialize writes for this SKU, including competing creates for
            // the same item/location position.
            $item = StockItem::query()->where('tenant_id', $tenant->id)
                ->lockForUpdate()->findOrFail($input['item_id']);
            $location = StockLocation::query()->where('tenant_id', $tenant->id)
                ->lockForUpdate()->findOrFail($input['location_id']);
            if (!$item->active || !$location->active) {
                throw ValidationException::withMessages([
                    'item_id' => 'A política exige item e local ativos.',
                ]);
            }
            $policy = StockReorderPolicy::query()->where('tenant_id', $tenant->id)
                ->where('item_id', $input['item_id'])
                ->where('location_id', $input['location_id'])
                ->lockForUpdate()->first();
            $created = $policy === null;
            $policy ??= new StockReorderPolicy([
                'tenant_id' => $tenant->id,
                'item_id' => $input['item_id'],
                'location_id' => $input['location_id'],
            ]);
            $fields = ['critical_quantity', 'safety_quantity', 'minimum_quantity', 'target_quantity'];
            $before = $created ? null : $policy->only($fields);
            $policy->fill(array_intersect_key($input, array_flip($fields)));
            if ($created || $policy->isDirty()) {
                $policy->save();
                $this->audit->write($tenant, $request->user(),
                    $created ? 'stock.reorder_policy.created' : 'stock.reorder_policy.updated',
                    $policy, $before, $policy->only($fields));
            }
            return [$policy->load(['item', 'location']), $created];
        }, 3);

        return (new StockReorderPolicyResource($policy))->response()->setStatusCode($created ? 201 : 200);
    }

    public function destroy(Request $request, int $item, int $location): JsonResponse
    {
        $this->portfolio->assertFull($request);
        DB::transaction(function () use ($request, $item, $location): void {
            $tenant = $request->attributes->get('nexa_tenant');
            StockItem::query()->where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($item);
            $policy = StockReorderPolicy::query()->where('tenant_id', $tenant->id)
                ->where('item_id', $item)->where('location_id', $location)
                ->lockForUpdate()->firstOrFail();
            $this->audit->write($tenant, $request->user(), 'stock.reorder_policy.deleted',
                $policy, $policy->only([
                    'item_id', 'location_id', 'critical_quantity', 'safety_quantity',
                    'minimum_quantity', 'target_quantity',
                ]), []);
            $policy->delete();
        }, 3);

        return response()->json(null, 204);
    }
}
