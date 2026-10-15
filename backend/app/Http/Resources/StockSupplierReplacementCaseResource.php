<?php
declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class StockSupplierReplacementCaseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
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
            'assignee'=>$this->whenLoaded('assignee', fn () => $this->assignee?->only(['id','name'])),
            'creator'=>$this->whenLoaded('creator', fn () => $this->creator?->only(['id','name'])),
            'return'=>$this->whenLoaded('supplierReturn', fn () => [
                'quantity'=>(int)$this->supplierReturn->quantity,
                'status'=>$this->supplierReturn->status,
                'movement_id'=>$this->supplierReturn->movement_id,
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
        ];
    }
}
