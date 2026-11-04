<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\CollectorAgent;
use App\Models\Printer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CollectorPrinterInventoryIngestor
{
    /** @param array<int,array<string,mixed>> $printers
     *  @return array<int,int>
     */
    public function ingest(CollectorAgent $agent, array $printers): array
    {
        $printerIds = collect($printers)->pluck('printer_id')->unique()->values();
        $allowed = Printer::query()
            ->where('tenant_id', $agent->tenant_id)
            ->where('customer_id', $agent->customer_id)
            ->where('active', true)
            ->whereIn('id', $printerIds)
            ->pluck('id');
        if ($allowed->count() !== $printerIds->count()) {
            throw ValidationException::withMessages([
                'printers' => 'A printer is not active or does not belong to the collector customer.',
            ]);
        }

        $now = now('UTC');
        $rows = collect($printers)->map(static function (array $item) use ($agent, $now): array {
            return [
                'tenant_id' => $agent->tenant_id,
                'customer_id' => $agent->customer_id,
                'collector_agent_id' => $agent->id,
                'printer_id' => (int) $item['printer_id'],
                'ip_address' => $item['ip_address'],
                'manufacturer' => $item['manufacturer'],
                'model' => $item['model'],
                'serial_number' => $item['serial_number'],
                'mac_address' => $item['mac_address'],
                'monitored' => (bool) $item['monitored'],
                'observed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        })->all();

        // Observations are untrusted device-reported data, isolated from
        // authoritative contract, billing and manually homologated printer fields.
        DB::table('collector_printer_observations')->upsert(
            $rows,
            ['collector_agent_id', 'printer_id'],
            [
                'ip_address', 'manufacturer', 'model', 'serial_number',
                'mac_address', 'monitored', 'observed_at', 'updated_at',
            ],
        );

        return $printerIds->map(static fn ($id): int => (int) $id)->all();
    }
}
