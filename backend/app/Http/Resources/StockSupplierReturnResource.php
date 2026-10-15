<?php
declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class StockSupplierReturnResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'request_id' => $this->request_id,
            'purchase_receipt_match_id' => (int) $this->purchase_receipt_match_id,
            'quantity' => (int) $this->quantity,
            'reason' => $this->reason,
            'status' => $this->status,
            'version' => (int) $this->version,
            'movement_id' => $this->movement_id,
            'review_note' => $this->review_note,
            'created_at' => $this->created_at?->toIso8601String(),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->only(['id','name'])),
            'reviewed_by' => $this->whenLoaded('reviewer', fn () => $this->reviewer?->only(['id','name'])),
            'receipt' => $this->whenLoaded('purchaseMatch', fn () => [
                'receipt_detail_id' => (int) $this->purchaseMatch->receipt_detail_id,
                'lot_number' => $this->purchaseMatch->receipt?->lot_number,
                'invoice_reference' => $this->purchaseMatch->receipt?->invoice_reference,
                'purchase_order_id' => (int) $this->purchaseMatch->purchase_order_id,
            ]),
        ];
    }
}
