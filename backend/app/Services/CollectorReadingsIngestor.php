<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\CollectorAgent;
use App\Models\Printer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CollectorReadingsIngestor
{
    /** @param array<int, array<string, mixed>> $samples
     *  @return array<int, string>
     */
    public function ingest(CollectorAgent $agent, array $samples): array
    {
        $printerIds = collect($samples)->pluck('printer_id')->unique()->values();
        $allowed = Printer::query()
            ->where('tenant_id', $agent->tenant_id)
            ->where('customer_id', $agent->customer_id)
            ->where('active', true)
            ->whereIn('id', $printerIds)
            ->pluck('id')->map(fn ($id): int => (int) $id);
        if ($allowed->count() !== $printerIds->count()) {
            throw ValidationException::withMessages(['samples' => 'Some printers do not belong to the registered collector customer.']);
        }

        $now = CarbonImmutable::now('UTC');
        $rows = [];
        foreach ($samples as $sample) {
            $collected = CarbonImmutable::parse($sample['collected_at'])->utc();
            if ($collected->greaterThan($now->addMinutes(5)) || $collected->lessThan($now->subDays(14))) {
                throw ValidationException::withMessages(['samples' => 'Sample collection timestamp is outside the accepted window.']);
            }
            $scan = $sample['meter_scan'] ?? null;
            if ($sample['meter_total'] === null && $sample['meter_mono'] === null && $sample['meter_color'] === null && $scan === null) {
                throw ValidationException::withMessages(['samples' => 'At least one real meter value is required.']);
            }
            $rows[] = [
                'tenant_id' => $agent->tenant_id, 'printer_id' => $sample['printer_id'],
                'sample_id' => $sample['sample_id'], 'meter_total' => $sample['meter_total'],
                'meter_mono' => $sample['meter_mono'], 'meter_color' => $sample['meter_color'],
                'meter_scan' => $scan, 'collected_at' => $collected, 'received_at' => $now,
            ];
        }

        DB::transaction(function () use ($agent, $rows, $now): void {
            DB::table('printer_readings')->insertOrIgnore($rows);
            $stored = DB::table('printer_readings')
                ->where('tenant_id', $agent->tenant_id)
                ->whereIn('sample_id', array_column($rows, 'sample_id'))
                ->get()->keyBy('sample_id');
            foreach ($rows as $row) {
                $existing = $stored->get($row['sample_id']);
                $matches = $existing
                    && (int) $existing->printer_id === (int) $row['printer_id']
                    && ($existing->meter_total === null ? $row['meter_total'] === null : (string) $existing->meter_total === (string) $row['meter_total'])
                    && ($existing->meter_mono === null ? $row['meter_mono'] === null : (string) $existing->meter_mono === (string) $row['meter_mono'])
                    && ($existing->meter_color === null ? $row['meter_color'] === null : (string) $existing->meter_color === (string) $row['meter_color'])
                    && ($existing->meter_scan === null ? $row['meter_scan'] === null : (string) $existing->meter_scan === (string) $row['meter_scan'])
                    && CarbonImmutable::parse($existing->collected_at, 'UTC')->equalTo($row['collected_at']);
                if (!$matches) {
                    throw ValidationException::withMessages(['samples' => 'Sample identifier conflicts with a previously accepted reading.']);
                }
            }
            $agent->update(['last_seen_at' => $now]);
        });
        return collect($samples)->pluck('sample_id')->values()->all();
    }
}
