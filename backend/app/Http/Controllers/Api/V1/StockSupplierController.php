<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StockSupplierRequest;
use App\Http\Requests\StockSupplierUpdateRequest;
use App\Models\StockSupplier;
use App\Services\CustomerPortfolio;
use App\Services\StockSupplyCatalogService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class StockSupplierController extends Controller
{
    public function __construct(
        private readonly StockSupplyCatalogService $catalog,
        private readonly CustomerPortfolio $portfolio,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->portfolio->assertFull($request);
        $filters = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'active' => ['sometimes', 'in:0,1,true,false'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);
        $term = mb_strtolower(trim($filters['q'] ?? ''));
        $query = StockSupplier::query()
            ->where('tenant_id', $request->attributes->get('nexa_tenant')->id);
        if ($term !== '') {
            $query->where(static function (Builder $match) use ($term): void {
                $match->whereRaw('LOWER(code) LIKE ?', ['%'.$term.'%'])
                    ->orWhereRaw('LOWER(name) LIKE ?', ['%'.$term.'%']);
            });
        }
        if (array_key_exists('active', $filters)) {
            $query->where('active', $request->boolean('active'));
        }
        return response()->json(
            $query->orderBy('name')->orderBy('id')->paginate($filters['per_page'] ?? 25)
        );
    }

    public function store(StockSupplierRequest $request): JsonResponse
    {
        $this->portfolio->assertFull($request);
        $supplier = $this->catalog->createSupplier(
            $request->attributes->get('nexa_tenant'), $request->user(), $request->validated()
        );
        return response()->json(['data' => $supplier->only([
            'id', 'code', 'name', 'email', 'document', 'active',
        ])], 201);
    }

    public function update(StockSupplierUpdateRequest $request, int $supplier): JsonResponse
    {
        $this->portfolio->assertFull($request);
        $updated = $this->catalog->updateSupplier(
            $request->attributes->get('nexa_tenant'), $request->user(),
            $supplier, $request->validated()
        );
        return response()->json(['data' => $updated->only([
            'id', 'code', 'name', 'email', 'document', 'active',
        ])]);
    }
}
