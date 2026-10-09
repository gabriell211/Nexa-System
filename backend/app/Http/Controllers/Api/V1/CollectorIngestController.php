<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\CollectorIngestRequest;
use App\Models\CollectorAgent;
use App\Models\Printer;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CollectorIngestController extends Controller
{
    public function __invoke(CollectorIngestRequest $request): JsonResponse
    {
        $token = $request->bearerToken();
        if (!$token || strlen($token) !== 64 || !ctype_xdigit($token)) {
            abort(401, 'Collector authentication required.');
        }
        $agent = CollectorAgent::query()
            ->where('token_hash', hash('sha256', $token))
            ->where('active', true)
            ->first();
        if (!$agent || $agent->collector_id !== $request->validated('collector_id')) {
            abort(401, 'Invalid collector credentials.');
        }

        $samples = $request->validated('samples');
        $printerIds = collect($samples)->pluck('printer_id')->unique()->values();
        $allowed = Printer::query()
            ->where('tenant_id', $agent->tenant_id)
            ->where('customer_id', $agent->customer_id)
            ->whereIn('id', $printerIds)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id);

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
            if ($sample['meter_total'] === null && $sample['meter_mono'] === null && $sample['meter_color'] === null) {
                throw ValidationException::withMessages(['samples' => 'At least one real meter value is required.']);
            }
            $rows[] = [
                'tenant_id' => $agent->tenant_id,
                'printer_id' => $sample['printer_id'],
                'sample_id' => $sample['sample_id'],
                'meter_total' => $sample['meter_total'],
                'meter_mono' => $sample['meter_mono'],
                'meter_color' => $sample['meter_color'],
                'collected_at' => $collected,
                'received_at' => $now,
            ];
        }

        DB::transaction(function () use ($agent, $rows, $now): void {
            DB::table('printer_readings')->insertOrIgnore($rows);

            // A repeated UUID is an ACK only when it represents the exact same reading.
            // Check after the insert to account for concurrent retries on PostgreSQL.
            $stored = DB::table('printer_readings')
                ->where('tenant_id', $agent->tenant_id)
                ->whereIn('sample_id', array_column($rows, 'sample_id'))
                ->get()
                ->keyBy('sample_id');

            foreach ($rows as $row) {
                $existing = $stored->get($row['sample_id']);
                $matches = $existing
                    && (int) $existing->printer_id === (int) $row['printer_id']
                    && ($existing->meter_total === null ? $row['meter_total'] === null : (string) $existing->meter_total === (string) $row['meter_total'])
                    && ($existing->meter_mono === null ? $row['meter_mono'] === null : (string) $existing->meter_mono === (string) $row['meter_mono'])
                    && ($existing->meter_color === null ? $row['meter_color'] === null : (string) $existing->meter_color === (string) $row['meter_color'])
                    && CarbonImmutable::parse($existing->collected_at, 'UTC')->equalTo($row['collected_at']);

                if (!$matches) {
                    throw ValidationException::withMessages([
                        'samples' => 'Sample identifier conflicts with a previously accepted reading.',
                    ]);
                }
            }

            $agent->update(['last_seen_at' => $now]);
        });

        // ACK means persisted or already known; clients may safely retry without duplicates.
        return response()->json(['acknowledged' => collect($samples)->pluck('sample_id')->values()]);
    }
}
