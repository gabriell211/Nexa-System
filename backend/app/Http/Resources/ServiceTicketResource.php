<?php
declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ServiceTicketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'=>$this->id,'customer_id'=>$this->customer_id,'printer_id'=>$this->printer_id,
            'assigned_user_id'=>$this->assigned_user_id,'subject'=>$this->subject,
            'description'=>$this->description,'category'=>$this->category,
            'priority'=>$this->priority,'status'=>$this->status,'origin'=>$this->origin,
            'created_at'=>$this->created_at,'updated_at'=>$this->updated_at,
            'first_responded_at'=>$this->first_responded_at,'resolved_at'=>$this->resolved_at,
            'closed_at'=>$this->closed_at,
            'customer'=>$this->whenLoaded('customer', fn () => $this->customer?->only(['id','name'])),
            'printer'=>$this->whenLoaded('printer', fn () => $this->printer?->only(['id','manufacturer','model','serial_number','ip_address'])),
            'assignee'=>$this->whenLoaded('assignee', fn () => $this->assignee?->only(['id','name'])),
            'opened_by_user_id'=>$this->opened_by_user_id,
            'opener'=>$this->whenLoaded('opener', fn () => $this->opener?->only(['id','name','email'])),
            'linked_printers'=>$this->whenLoaded('linkedPrinters', fn () =>
                $this->linkedPrinters->map(static fn ($printer) =>
                    $printer->only(['id','manufacturer','model','serial_number','ip_address']))),
            'attachments'=>$this->whenLoaded('attachments', fn () =>
                $this->attachments->map(static fn ($file): array => [
                    'id'=>$file->id,
                    'name'=>$file->original_name,
                    'mime_type'=>$file->mime_type,
                    'size'=>(int)$file->bytes,
                ])),
            'signature'=>$this->whenLoaded('signature', fn () => $this->signature ? [
                'signer_name'=>$this->signature->signer_name,
                'service_summary'=>$this->signature->service_summary,
                'sha256'=>$this->signature->sha256,
                'signed_at'=>$this->signature->signed_at,
            ] : null),
            'part_requests'=>$this->whenLoaded('partRequests', fn () =>
                $this->partRequests->map(static fn ($part): array => [
                    'id'=>$part->id,'stock_item_id'=>$part->stock_item_id,
                    'quantity'=>$part->quantity,'reason'=>$part->reason,
                    'status'=>$part->status,'created_at'=>$part->created_at,
                    'item'=>$part->relationLoaded('stockItem')
                        ? $part->stockItem?->only(['id','sku','name']) : null,
                ])),
            'comments'=>$this->whenLoaded('comments', fn () => $this->comments->map(
                static fn ($comment): array => [
                    'id'=>$comment->id,'body'=>$comment->body,'internal'=>(bool)$comment->internal,
                    'created_at'=>$comment->created_at,
                    'author'=>$comment->relationLoaded('author') ? $comment->author?->only(['id','name']) : null,
                ],
            )),
        ];
    }
}
