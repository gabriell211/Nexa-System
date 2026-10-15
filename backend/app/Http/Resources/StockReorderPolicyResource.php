<?php
declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class StockReorderPolicyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $result = [
            'id' => $this->id,
            'item_id' => $this->item_id,
            'location_id' => $this->location_id,
            'critical_quantity' => (int) $this->critical_quantity,
            'safety_quantity' => (int) $this->safety_quantity,
            'minimum_quantity' => (int) $this->minimum_quantity,
            'target_quantity' => (int) $this->target_quantity,
            'item' => new StockItemResource($this->whenLoaded('item')),
            'location' => new StockLocationResource($this->whenLoaded('location')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];

        if (array_key_exists('physical_quantity', $this->getAttributes())) {
            $physical = (int) $this->physical_quantity;
            $reserved = (int) $this->reserved_quantity;
            $expired = (int) $this->expired_quantity;
            $available = max(0, $physical - $expired - $reserved);
            $active = $this->item->active && $this->location->active;
            $result += [
                'physical_quantity' => $physical,
                'reserved_quantity' => $reserved,
                'expired_quantity' => $expired,
                'available_quantity' => $available,
                'status' => !$active ? 'inactive'
                    : ($available <= $this->critical_quantity ? 'critical'
                        : ($available <= $this->safety_quantity ? 'safety'
                            : ($available <= $this->minimum_quantity ? 'low' : 'ok'))),
                'suggested_quantity' => $active ? max(0, (int) $this->target_quantity - $available) : 0,
            ];
        }

        return $result;
    }
}
