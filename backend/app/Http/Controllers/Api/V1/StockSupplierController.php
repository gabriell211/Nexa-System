<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StockSupplierRequest;
use App\Models\StockSupplier;
use App\Services\CustomerPortfolio;
use App\Services\StockSupplyCatalogService;
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
        $filters=$request->validate([
            'page'=>['sometimes','integer','min:1'],
            'per_page'=>['sometimes','integer','between:1,100'],
        ]);
        $result=StockSupplier::query()->where('tenant_id',$request->attributes->get('nexa_tenant')->id)
            ->orderBy('name')->paginate($filters['per_page']??25);
        return response()->json($result);
    }

    public function store(StockSupplierRequest $request): JsonResponse
    {
        $this->portfolio->assertFull($request);
        $supplier=$this->catalog->createSupplier(
            $request->attributes->get('nexa_tenant'),$request->user(),$request->validated()
        );
        return response()->json(['data'=>$supplier->only(['id','code','name','email','document','active'])],201);
    }
}
