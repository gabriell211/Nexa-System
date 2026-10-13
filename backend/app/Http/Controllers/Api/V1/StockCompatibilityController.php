<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StockCompatibilityRequest;
use App\Models\StockItem;
use App\Models\StockItemCompatibility;
use App\Services\CustomerPortfolio;
use App\Services\StockSupplyCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class StockCompatibilityController extends Controller
{
    public function __construct(
        private readonly StockSupplyCatalogService $catalog,
        private readonly CustomerPortfolio $portfolio,
    ) {}

    public function index(Request $request, int $item): JsonResponse
    {
        $this->portfolio->assertFull($request);
        $tenant=$request->attributes->get('nexa_tenant');
        StockItem::query()->where('tenant_id',$tenant->id)->findOrFail($item);
        $matches=StockItemCompatibility::query()->where('tenant_id',$tenant->id)
            ->where('item_id',$item)->orderBy('printer_manufacturer')->orderBy('printer_model')->get();
        return response()->json(['data'=>$matches->map(
            static fn ($row): array => $row->only(['id','printer_manufacturer','printer_model'])
        )]);
    }

    public function store(StockCompatibilityRequest $request, int $item): JsonResponse
    {
        $this->portfolio->assertFull($request);
        $record=$this->catalog->addCompatibility(
            $request->attributes->get('nexa_tenant'),$request->user(),$item,$request->validated()
        );
        return response()->json(['data'=>$record->only(['id','printer_manufacturer','printer_model'])],201);
    }

    public function destroy(Request $request,int $item,int $compatibility): JsonResponse
    {
        $this->portfolio->assertFull($request);
        if (!in_array($request->attributes->get('nexa_role'),['owner','admin','manager'],true)) abort(403);
        $this->catalog->removeCompatibility(
            $request->attributes->get('nexa_tenant'),$request->user(),$item,$compatibility
        );
        return response()->json(null,204);
    }

    public function check(Request $request,int $item): JsonResponse
    {
        $this->portfolio->assertFull($request);
        $input=$request->validate(['printer_id'=>['required','integer','min:1']]);
        $tenant=$request->attributes->get('nexa_tenant');
        StockItem::query()->where('tenant_id',$tenant->id)->findOrFail($item);
        $printer=$tenant->printers()->findOrFail($input['printer_id']);
        $matched=StockItemCompatibility::query()->where('tenant_id',$tenant->id)
            ->where('item_id',$item)
            ->where('printer_manufacturer',StockSupplyCatalogService::normalize($printer->manufacturer))
            ->where('printer_model',StockSupplyCatalogService::normalize($printer->model))
            ->exists();

        // Absence of catalog evidence is unknown, not proof of incompatibility.
        return response()->json(['status'=>$matched?'confirmed':'unknown','confirmed'=>$matched]);
    }
}
