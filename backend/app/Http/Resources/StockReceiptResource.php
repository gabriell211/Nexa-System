<?php
declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class StockReceiptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'=>$this->id,'movement_id'=>$this->movement_id,
            'supplier_id'=>$this->supplier_id,'invoice_reference'=>$this->invoice_reference,
            'lot_number'=>$this->lot_number,'expires_on'=>$this->expires_on?->toDateString(),
            'unit_cost_cents'=>$this->unit_cost_cents,
            'supplier'=>$this->whenLoaded('supplier',fn (): array => $this->supplier->only(['id','code','name'])),
            'movement'=>$this->whenLoaded('movement',fn (): array => [
                'id'=>$this->movement->id,'quantity'=>$this->movement->quantity,
                'item_id'=>$this->movement->item_id,
                'destination_location_id'=>$this->movement->destination_location_id,
                'item'=>$this->movement->relationLoaded('item')
                    ? $this->movement->item?->only(['id','sku','name']):null,
                'destination'=>$this->movement->relationLoaded('destination')
                    ? $this->movement->destination?->only(['id','name']):null,
            ]),
        ];
    }
}
