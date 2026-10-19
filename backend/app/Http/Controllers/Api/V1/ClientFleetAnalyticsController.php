<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

/**
 * Read-only, customer-scoped evidence of changes observed between actual
 * samples on the SAME UTC day. This is not a billable page-volume report.
 */
final class ClientFleetAnalyticsController extends Controller
{
    private const MAX_SAMPLES = 20000;

    public function __invoke(Request $request): JsonResponse
    {
        if ($request->attributes->get('nexa_role') !== 'customer'
            || $request->attributes->get('nexa_customer_scope') !== 'assigned') {
            abort(403);
        }
        if (!app(\App\Services\CustomerAccountPermissions::class)->forRequest($request)['view_reports']) abort(403);

        $tenant = $request->attributes->get('nexa_tenant');
        $allowed = DB::table('customer_user_access')
            ->select('customer_id')
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $request->user()->id);
        $printerIds = DB::table('printers')
            ->select('id')
            ->where('tenant_id', $tenant->id)
            ->whereIn('customer_id', $allowed);

        $today = CarbonImmutable::now('UTC')->startOfDay();
        $begin = $today->subDays(29);
        // Do not treat future-dated samples as valid data.
        $samples = DB::table('printer_readings')
            ->select('printer_id', 'collected_at', 'id', 'meter_mono', 'meter_total')
            ->where('tenant_id', $tenant->id)
            ->whereIn('printer_id', $printerIds)
            ->where('collected_at', '>=', $begin->format('Y-m-d H:i:s'))
            ->where('collected_at', '<=', CarbonImmutable::now('UTC')->format('Y-m-d H:i:s'))
            ->orderBy('printer_id')->orderBy('collected_at')->orderBy('id')
            ->limit(self::MAX_SAMPLES + 1)->get();

        if ($samples->count() > self::MAX_SAMPLES) {
            // Avoid presenting truncated figures as complete. An aggregate
            // SQL implementation is required for very large fleets.
            return response()->json([
                'message' => 'Volume de leituras superior ao limite desta consulta. Contate o suporte para geração de relatório.'
            ], 422)->header('Cache-Control', 'no-store, private');
        }

        // first / last snapshots are kept per device/day/metric, never
        // combined across different days or hardware.
        $buckets = [];
        foreach ($samples as $sample) {
            $day = substr((string) $sample->collected_at, 0, 10);
            $key = (string) $sample->printer_id.'|'.$day;
            if (!isset($buckets[$key])) {
                $buckets[$key] = [
                    'printer_id' => (int) $sample->printer_id,
                    'day' => $day,
                    'mono' => ['previous' => null, 'difference' => '0', 'count' => 0, 'invalid' => false],
                    'total' => ['previous' => null, 'difference' => '0', 'count' => 0, 'invalid' => false],
                ];
            }
            foreach (['mono' => 'meter_mono', 'total' => 'meter_total'] as $channel => $field) {
                $value = $sample->{$field};
                if ($value === null || preg_match('/^\d+$/D', (string) $value) !== 1) {
                    $buckets[$key][$channel]['invalid'] = true;
                    continue;
                }
                $value = (string) $value;
                $previous = $buckets[$key][$channel]['previous'];
                if ($previous !== null) {
                    if (bccomp($value, $previous, 0) < 0) {
                        $buckets[$key][$channel]['invalid'] = true;
                    } else {
                        $buckets[$key][$channel]['difference'] = bcadd(
                            $buckets[$key][$channel]['difference'],
                            bcsub($value, $previous, 0), 0
                        );
                    }
                }
                $buckets[$key][$channel]['previous'] = $value;
                $buckets[$key][$channel]['count']++;
            }
        }

        $days = [];
        for ($i = 0; $i < 30; $i++) {
            $key = $begin->addDays($i)->format('Y-m-d');
            $days[$key] = ['date' => $key, 'mono' => null, 'total' => null,
                'devices_with_evidence' => 0];
        }
        $ranking = [];
        foreach ($buckets as $bucket) {
            $day = $bucket['day'];
            $usable = false;
            foreach (['mono', 'total'] as $channel) {
                $entry = $bucket[$channel];
                if ($entry['invalid'] || $entry['count'] < 2) {
                    continue;
                }
                $delta = $entry['difference'];
                $days[$day][$channel] = bcadd($days[$day][$channel] ?? '0', $delta, 0);
                if ($channel === 'mono') {
                    $id = $bucket['printer_id'];
                    $ranking[$id] = bcadd($ranking[$id] ?? '0', $delta, 0);
                }
                $usable = true;
            }
            if ($usable) {
                $days[$day]['devices_with_evidence']++;
            }
        }
        uasort($ranking, static fn (string $a, string $b): int => bccomp($b, $a, 0));
        $ids = array_slice(array_keys($ranking), 0, 5);
        $names = DB::table('printers')
            ->where('tenant_id', $tenant->id)
            ->whereIn('customer_id', $allowed)
            ->whereIn('id', $ids)
            ->get(['id', 'manufacturer', 'model', 'ip_address'])
            ->keyBy('id');

        return response()->json([
            'period' => [
                'start' => $begin->format('Y-m-d'),
                'end' => $today->format('Y-m-d'),
                'timezone' => 'UTC',
            ],
            'days' => array_values($days),
            'top_printers' => array_values(array_filter(array_map(
                static fn ($id): ?array => isset($names[$id]) ? [
                    'id' => (int) $id,
                    'name' => $names[$id]->manufacturer.' '.$names[$id]->model,
                    'ip_address' => $names[$id]->ip_address,
                    'mono_observed_delta' => $ranking[$id],
                ] : null,
                $ids
            ))),
            'method' => 'observed_same_day_differences_only',
            'not_billing_approved' => true,
            'coverage' => 'partial_unless_each_printer_has_valid_samples_for_each_day',
        ])->header('Cache-Control', 'no-store, private');
    }
}
