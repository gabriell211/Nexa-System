<?php
declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class PrinterResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer_id' => $this->customer_id,
            'manufacturer' => $this->manufacturer,
            'model' => $this->model,
            'serial_number' => $this->serial_number,
            'ip_address' => $this->ip_address,
            'status' => $this->status,
            'active' => $this->active,
            'customer' => $this->whenLoaded('customer', fn (): array => [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
            ]),
            'readings' => $this->whenLoaded('readings', fn () => $this->readings->map(
                fn ($reading): array => $reading->only([
                    'sample_id', 'meter_total', 'meter_mono',
                    'meter_color', 'collected_at', 'received_at',
                ])
            )),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
