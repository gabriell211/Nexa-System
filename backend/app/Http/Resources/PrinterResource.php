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
            'asset_number' => $this->asset_number,
            'mac_address' => $this->mac_address,
            'firmware_version' => $this->firmware_version,
            'owner_type' => $this->owner_type,
            'connection_type' => $this->connection_type,
            'color_capability' => $this->color_capability,
            'status' => $this->status,
            'active' => $this->active,
            'current_assignment' => $this->whenLoaded('currentAssignment',
                fn () => $this->currentAssignment
                    ? (new PrinterAssignmentResource($this->currentAssignment))->toArray($request)
                    : null
            ),
            'customer' => $this->whenLoaded('customer', fn (): array => [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
            ]),
            'latest_reading' => $this->whenLoaded('latestReading', fn (): ?array =>
                $this->latestReading
                    ? [
                        'meter_total' => $this->latestReading->meter_total === null
                            ? null : (string) $this->latestReading->meter_total,
                        'meter_mono' => $this->latestReading->meter_mono === null
                            ? null : (string) $this->latestReading->meter_mono,
                        'meter_color' => $this->latestReading->meter_color === null
                            ? null : (string) $this->latestReading->meter_color,
                        'meter_scan' => $this->latestReading->meter_scan === null
                            ? null : (string) $this->latestReading->meter_scan,
                        'collected_at' => $this->latestReading->collected_at?->toIso8601String(),
                        'received_at' => $this->latestReading->received_at?->toIso8601String(),
                    ]
                    : null
            ),
            'readings' => $this->whenLoaded('readings', fn () => $this->readings->map(
                fn ($reading): array => array_replace($reading->only([
                    'sample_id', 'meter_total', 'meter_mono',
                    'meter_color', 'meter_scan', 'collected_at', 'received_at',
                ]), [
                    'meter_total' => $reading->meter_total === null ? null : (string) $reading->meter_total,
                    'meter_mono' => $reading->meter_mono === null ? null : (string) $reading->meter_mono,
                    'meter_color' => $reading->meter_color === null ? null : (string) $reading->meter_color,
                    'meter_scan' => $reading->meter_scan === null ? null : (string) $reading->meter_scan,
                ])
            )),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
