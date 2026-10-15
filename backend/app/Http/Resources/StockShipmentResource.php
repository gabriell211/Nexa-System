<?php
declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class StockShipmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'=>$this->id, 'request_id'=>$this->request_id,
            'replenishment_plan_item_id'=>$this->replenishment_plan_item_id,
            'replenishment_plan_id'=>$this->whenLoaded('replenishmentLine', fn () => $this->replenishmentLine?->plan_id),
            'item_id'=>$this->item_id, 'source_location_id'=>$this->source_location_id,
            'destination_location_id'=>$this->destination_location_id,
            'quantity'=>$this->quantity, 'shipped_quantity'=>$this->shipped_quantity,
            'received_quantity'=>$this->received_quantity, 'lost_quantity'=>$this->lost_quantity,
            'cancelled_quantity'=>$this->cancelled_quantity,
            'reserved_quantity'=>$this->quantity - $this->shipped_quantity - $this->cancelled_quantity,
            'in_transit_quantity'=>$this->shipped_quantity - $this->received_quantity - $this->lost_quantity,
            'status'=>$this->status, 'reason'=>$this->reason,
            'lot_transit'=>$this->whenLoaded('lotTransit', fn () => $this->lotTransit->map(
                static fn ($t): array => [
                    'lot_id'=>$t->lot_id,'shipped_quantity'=>$t->shipped_quantity,
                    'received_quantity'=>$t->received_quantity,'lost_quantity'=>$t->lost_quantity,
                ]
            )),
            'created_at'=>$this->created_at, 'updated_at'=>$this->updated_at,
            'item'=>new StockItemResource($this->whenLoaded('item')),
            'source'=>new StockLocationResource($this->whenLoaded('source')),
            'destination'=>new StockLocationResource($this->whenLoaded('destination')),
            'events'=>$this->whenLoaded('events', fn () => $this->events->map(
                static fn ($event): array => [
                    'id'=>$event->id,'kind'=>$event->kind,'quantity'=>$event->quantity,
                    'reason'=>$event->reason,'created_at'=>$event->created_at,
                    'actor'=>$event->relationLoaded('actor') ? $event->actor?->only(['id','name']) : null,
                ]
            )),
        ];
    }
}
