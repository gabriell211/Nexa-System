<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\StockItem;
use App\Models\StockItemCompatibility;
use App\Models\StockSupplier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class StockSupplyCatalogService
{
    public function __construct(private readonly AuditWriter $audit) {}

    public function createSupplier(Tenant $tenant, User $actor, array $input): StockSupplier
    {
        return DB::transaction(function () use ($tenant,$actor,$input): StockSupplier {
            $supplier = StockSupplier::query()->create([
                ...$input,'tenant_id'=>$tenant->id,'active'=>true,
            ]);
            $this->audit->write($tenant,$actor,'stock.supplier.created',$supplier,null,
                $supplier->only(['code','name','email','document','active']));
            return $supplier;
        });
    }

    public function addCompatibility(Tenant $tenant, User $actor, int $itemId, array $input): StockItemCompatibility
    {
        return DB::transaction(function () use ($tenant,$actor,$itemId,$input): StockItemCompatibility {
            $item = StockItem::query()->where('tenant_id',$tenant->id)->lockForUpdate()->findOrFail($itemId);
            $maker = self::normalize($input['printer_manufacturer']);
            $model = self::normalize($input['printer_model']);
            if (mb_strlen($maker)>120 || mb_strlen($model)>160 || mb_strlen($maker)<2 || mb_strlen($model)<2) {
                throw ValidationException::withMessages(['printer_model'=>'Marca e modelo inválidos.']);
            }
            $exists = StockItemCompatibility::query()->where('tenant_id',$tenant->id)
                ->where('item_id',$item->id)->where('printer_manufacturer',$maker)
                ->where('printer_model',$model)->exists();
            if ($exists) {
                throw ValidationException::withMessages(['printer_model'=>'Compatibilidade já cadastrada.']);
            }
            $compatibility = StockItemCompatibility::query()->create([
                'tenant_id'=>$tenant->id,'item_id'=>$item->id,
                'printer_manufacturer'=>$maker,'printer_model'=>$model,'created_by'=>$actor->id,
            ]);
            $this->audit->write($tenant,$actor,'stock.compatibility.created',$compatibility,null,
                $compatibility->only(['item_id','printer_manufacturer','printer_model']));
            return $compatibility;
        });
    }

    public function removeCompatibility(Tenant $tenant, User $actor, int $itemId, int $compatibilityId): void
    {
        DB::transaction(function () use ($tenant,$actor,$itemId,$compatibilityId): void {
            StockItem::query()->where('tenant_id',$tenant->id)->lockForUpdate()->findOrFail($itemId);
            $record=StockItemCompatibility::query()->where('tenant_id',$tenant->id)
                ->where('item_id',$itemId)->findOrFail($compatibilityId);
            $before=$record->only(['item_id','printer_manufacturer','printer_model']);
            $this->audit->write($tenant,$actor,'stock.compatibility.removed',$record,$before,
                ['deleted'=>true]);
            $record->delete();
        });
    }

    public static function normalize(string $value): string
    {
        $collapsed=preg_replace('/\s+/u',' ',trim($value));
        return mb_strtolower($collapsed ?? trim($value));
    }
}
