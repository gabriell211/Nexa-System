<?php
declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class PrinterAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'printer_id' => $this->printer_id,
            'customer_id' => $this->customer_id,
            'location_id' => $this->location_id,
            'department_id' => $this->department_id,
            'cost_center_id' => $this->cost_center_id,
            'reason' => $this->reason,
            'assigned_at' => $this->assigned_at,
            'released_at' => $this->released_at,
            'location' => $this->whenLoaded('location', fn (): array => [
                'id' => $this->location->id, 'name' => $this->location->name,
            ]),
            'department' => $this->whenLoaded('department', fn (): ?array =>
                $this->department?->only(['id', 'name'])
            ),
            'cost_center' => $this->whenLoaded('costCenter', fn (): ?array =>
                $this->costCenter?->only(['id', 'name', 'code'])
            ),
            'actor' => $this->whenLoaded('actor', fn (): ?array => $this->actor?->only(['id', 'name'])),
        ];
    }
}
