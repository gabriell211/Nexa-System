<?php
declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class StockSupplierReplacementCaseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $documentedQuantity = $this->relationLoaded('replacementReceipts')
            ? (int) $this->replacementReceipts->sum('quantity')
            : (int) ($this->documented_quantity ?? 0);
        $returnQuantity = $this->relationLoaded('supplierReturn')
            ? (int) $this->supplierReturn->quantity : null;
        return [
            'id'=>(int)$this->id,
            'supplier_return_id'=>(int)$this->supplier_return_id,
            'assigned_user_id'=>(int)$this->assigned_user_id,
            'status'=>$this->status,
            'version'=>(int)$this->version,
            'due_on'=>$this->due_on?->format('Y-m-d'),
            'overdue'=>$this->status === 'awaiting_supplier' && $this->due_on?->isBefore(today()) === true,
            'supplier_reference'=>$this->supplier_reference,
            'supplier_confirmed_at'=>$this->supplier_confirmed_at?->toIso8601String(),
            'documented_quantity'=>$documentedQuantity,
            'remaining_quantity'=>$returnQuantity === null ? null : max(0, $returnQuantity - $documentedQuantity),
            'assignee'=>$this->whenLoaded('assignee', fn () => $this->assignee?->only(['id','name'])),
            'creator'=>$this->whenLoaded('creator', fn () => $this->creator?->only(['id','name'])),
            'return'=>$this->whenLoaded('supplierReturn', fn () => [
                'quantity'=>(int)$this->supplierReturn->quantity,
                'status'=>$this->supplierReturn->status,
                'movement_id'=>$this->supplierReturn->movement_id,
                'purchase_receipt_match_id'=>(int)$this->supplierReturn->purchase_receipt_match_id,
                'purchase_order_line_id'=>$this->supplierReturn->purchaseMatch?->purchase_order_line_id,
                'reviewed_at'=>$this->supplierReturn->reviewed_at?->toIso8601String(),
            ]),
            'events'=>$this->whenLoaded('events', fn () => $this->events->map(
                static fn ($event): array => [
                    'id'=>(int)$event->id,
                    'action'=>$event->action,
                    'note'=>$event->note,
                    'supplier_reference'=>$event->supplier_reference,
                    'due_on'=>$event->due_on?->format('Y-m-d'),
                    'actor'=>$event->actor?->only(['id','name']),
                    'created_at'=>$event->created_at?->toIso8601String(),
                ]
            )->values()),
            'replacement_receipts'=>$this->whenLoaded('replacementReceipts', fn () =>
                $this->replacementReceipts->map(static fn ($link): array => [
                    'id'=>(int)$link->id,
                    'purchase_receipt_match_id'=>(int)$link->purchase_receipt_match_id,
                    'quantity'=>(int)$link->quantity,
                    'note'=>$link->note,
                    'invoice_reference'=>$link->purchaseMatch?->receipt?->invoice_reference,
                    'lot_number'=>$link->purchaseMatch?->receipt?->lot_number,
                    'creator'=>$link->creator?->only(['id','name']),
                    'created_at'=>$link->created_at?->toIso8601String(),
                ])->values()),
        ];
    }
}
