<?php
declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class StockReplenishmentPlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $person = static fn ($user): ?array => $user === null ? null : [
            'id' => (int) $user->id, 'name' => $user->name,
        ];

        return [
            'id' => (int) $this->id,
            'request_id' => $this->request_id,
            'status' => $this->status,
            'version' => (int) $this->version,
            'due_on' => $this->due_on?->toDateString(),
            'responsible_user_id' => (int) $this->responsible_user_id,
            'responsible' => $person($this->responsible),
            'created_by' => $person($this->createdBy),
            'approved_by' => $person($this->approvedBy),
            'cancelled_by' => $person($this->cancelledBy),
            'notes' => $this->notes,
            'review_note' => $this->review_note,
            'cancel_reason' => $this->cancel_reason,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'items_count' => $this->whenCounted('items'),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(static fn ($line): array => [
                'id' => (int) $line->id,
                'item_id' => (int) $line->item_id,
                'location_id' => (int) $line->location_id,
                'policy_id' => $line->policy_id === null ? null : (int) $line->policy_id,
                'origin' => $line->origin,
                'available_quantity' => $line->available_quantity,
                'suggested_quantity' => $line->suggested_quantity,
                'quantity' => (int) $line->quantity,
                'safety_item' => (bool) $line->safety_item,
                'note' => $line->note,
                'item' => new StockItemResource($line->item),
                'location' => new StockLocationResource($line->location),
            ])),
        ];
    }
}
