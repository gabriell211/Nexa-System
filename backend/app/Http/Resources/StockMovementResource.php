<?php
declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class StockMovementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'=>$this->id,'request_id'=>$this->request_id,'kind'=>$this->kind,
            'item_id'=>$this->item_id,'source_location_id'=>$this->source_location_id,
            'destination_location_id'=>$this->destination_location_id,
            'quantity'=>(int) $this->quantity,
            'source_before'=>$this->source_before,'source_after'=>$this->source_after,
            'destination_before'=>$this->destination_before,'destination_after'=>$this->destination_after,
            'reason'=>$this->reason,'created_at'=>$this->created_at,
            'item'=>new StockItemResource($this->whenLoaded('item')),
            'source'=>new StockLocationResource($this->whenLoaded('source')),
            'destination'=>new StockLocationResource($this->whenLoaded('destination')),
            'actor'=>$this->whenLoaded('actor', fn () => $this->actor?->only(['id','name'])),
        ];
    }
}
