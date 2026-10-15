<?php
declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class StockPurchaseOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'request_id' => $this->request_id,
            'plan_id' => (int) $this->plan_id,
            'supplier_id' => (int) $this->supplier_id,
            'status' => $this->status,
            'version' => (int) $this->version,
            'notes' => $this->notes,
            'review_note' => $this->review_note,
            'cancel_reason' => $this->cancel_reason,
            'supplier' => $this->whenLoaded('supplier', fn () =>
                $this->supplier?->only(['id','code','name','active'])),
            'created_by' => $this->whenLoaded('creator', fn () =>
                $this->creator?->only(['id','name'])),
            'approved_by' => $this->whenLoaded('approver', fn () =>
                $this->approver?->only(['id','name'])),
            'created_at' => $this->created_at?->toIso8601String(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'total_cents' => $this->whenLoaded('lines', fn () => $this->lines->sum(
                static fn ($line): int => $line->quantity * $line->unit_cost_cents
            )),
            'items_count' => $this->whenCounted('lines'),
            'items' => $this->whenLoaded('lines', fn () => $this->lines->map(
                static fn ($line): array => [
                    'id' => (int) $line->id,
                    'plan_line_id' => (int) $line->plan_line_id,
                    'quantity' => (int) $line->quantity,
                    'unit_cost_cents' => (int) $line->unit_cost_cents,
                    'line_total_cents' => $line->quantity * $line->unit_cost_cents,
                    'item' => $line->planLine?->item?->only(['id','sku','name','unit']),
                    'destination' => $line->planLine?->location?->only(['id','name']),
                ]
            )),
        ];
    }
}
