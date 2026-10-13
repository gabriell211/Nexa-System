<?php
declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class StockBalanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['item_id'=>$this->item_id,'location_id'=>$this->location_id,
            'quantity'=>(int) $this->quantity,
            'reserved_quantity'=>(int) $this->reserved_quantity,
            'available_quantity'=>(int) $this->available_quantity,
            'item'=>new StockItemResource($this->whenLoaded('item')),
            'location'=>new StockLocationResource($this->whenLoaded('location'))];
    }
}
