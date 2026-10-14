<?php
declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class PrinterAlertResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'=>$this->id,
            'customer_id'=>$this->customer_id,
            'printer_id'=>$this->printer_id,
            'ticket_id'=>$this->ticket_id,
            'origin'=>$this->origin,
            'telemetry_anchor_id'=>$this->telemetry_anchor_id,
            'code'=>$this->code,
            'category'=>$this->category,
            'severity'=>$this->severity,
            'status'=>$this->status,
            'title'=>$this->title,
            'description'=>$this->description,
            'created_at'=>$this->created_at,
            'acknowledged_at'=>$this->acknowledged_at,
            'resolved_at'=>$this->resolved_at,
            'ignored_at'=>$this->ignored_at,
            'customer'=>$this->whenLoaded('customer', fn()=> $this->customer?->only(['id','name'])),
            'printer'=>$this->whenLoaded('printer', fn()=> $this->printer?->only(['id','manufacturer','model'])),
            'ticket'=>$this->whenLoaded('ticket', fn()=> $this->ticket?->only(['id','subject','status'])),
            'creator'=>$this->whenLoaded('creator', fn()=> $this->creator?->only(['id','name'])),
        ];
    }
}
