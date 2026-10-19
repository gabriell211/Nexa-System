<?php
declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Public-to-assigned-customer, read-only subset of the equipment record. */
final class ClientPrinterResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $assignment = $this->currentAssignment;
        $reading = $this->latestReading;

        return [
            'id' => $this->id,
            'customer_name' => $this->customer?->name,
            'manufacturer' => $this->manufacturer,
            'model' => $this->model,
            'serial_number' => $this->serial_number,
            'ip_address' => $this->ip_address,
            'asset_number' => $this->asset_number,
            'owner_type' => $this->owner_type,
            'status' => $this->status,
            'active' => (bool) $this->active,
            'location' => $assignment?->location?->name,
            'department' => $assignment?->department?->name,
            'cost_center' => $assignment?->costCenter?->name,
            'cost_center_code' => $assignment?->costCenter?->code,
            'latest_reading' => app(\App\Services\CustomerAccountPermissions::class)->forRequest($request)['view_reports'] && $reading ? [
                // Decimal strings avoid IEEE-754 precision loss in JavaScript.
                'meter_total' => $reading->meter_total === null ? null : (string) $reading->meter_total,
                'meter_mono' => $reading->meter_mono === null ? null : (string) $reading->meter_mono,
                'meter_color' => $reading->meter_color === null ? null : (string) $reading->meter_color,
                'meter_scan' => $reading->meter_scan === null ? null : (string) $reading->meter_scan,
                'collected_at' => $reading->collected_at?->toIso8601String(),
            ] : null,
        ];
    }
}
