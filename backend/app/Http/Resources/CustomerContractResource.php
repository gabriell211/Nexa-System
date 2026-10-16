<?php
declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CustomerContractResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer_id' => $this->customer_id,
            'customer_name' => $this->whenLoaded('customer', fn (): string => $this->customer->name),
            'document_number' => $this->document_number,
            'title' => $this->title,
            'provider_unit_name' => $this->provider_unit_name,
            'billing_address' => $this->billing_address,
            'billing_timezone' => $this->billing_timezone,
            'effective_on' => $this->effective_on->toDateString(),
            'billing_starts_on' => $this->billing_starts_on->toDateString(),
            'ends_on' => $this->ends_on?->toDateString(),
            'active' => $this->active,
            'is_default' => $this->is_default,
            'version' => $this->version,
            'notes' => $this->notes,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
