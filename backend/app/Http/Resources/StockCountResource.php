<?php
declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class StockCountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'request_id' => $this->request_id,
            'item_id' => $this->item_id,
            'location_id' => $this->location_id,
            'expected_quantity' => (int) $this->expected_quantity,
            'counted_quantity' => (int) $this->counted_quantity,
            'observed_movement_id' => $this->observed_movement_id,
            'difference' => (int) $this->counted_quantity - (int) $this->expected_quantity,
            'reason' => $this->reason,
            'status' => $this->status,
            'counted_at' => $this->counted_at,
            'reviewed_at' => $this->reviewed_at,
            'review_reason' => $this->review_reason,
            'movement_id' => $this->movement_id,
            'item' => new StockItemResource($this->whenLoaded('item')),
            'location' => new StockLocationResource($this->whenLoaded('location')),
            'counted_by' => $this->whenLoaded('countedBy', fn () => $this->countedBy?->only(['id', 'name'])),
            'reviewed_by' => $this->whenLoaded('reviewedBy', fn () => $this->reviewedBy?->only(['id', 'name'])),
        ];
    }
}
