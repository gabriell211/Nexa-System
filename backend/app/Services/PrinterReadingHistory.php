<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Printer;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class PrinterReadingHistory
{
    /**
     * Paginate authentic persisted samples in UTC source-time order.
     * Never calculate consumption by subtracting meters: devices can reset,
     * roll over or report unsupported channels as null.
     *
     * @param array<string, mixed> $filters
     */
    public function paginate(Printer $printer,array $filters): LengthAwarePaginator
    {
        $query=DB::table('printer_readings')
            ->where('tenant_id',$printer->tenant_id)
            ->where('printer_id',$printer->id);

        if (isset($filters['start_date'])) {
            $query->where('collected_at','>=',
                $filters['start_date'].' 00:00:00');
        }
        if (isset($filters['end_date'])) {
            $exclusive=CarbonImmutable::parse(
                $filters['end_date'].' 00:00:00','UTC'
            )->addDay()->format('Y-m-d H:i:s');
            $query->where('collected_at','<',$exclusive);
        }

        return $query->orderByDesc('collected_at')->orderByDesc('id')
            ->paginate((int)($filters['per_page']??25))
            ->through(static function (object $row): array {
                return [
                    'id'=>(int)$row->id,
                    'meter_total'=>$row->meter_total === null ? null : (string)$row->meter_total,
                    'meter_mono'=>$row->meter_mono === null ? null : (string)$row->meter_mono,
                    'meter_color'=>$row->meter_color === null ? null : (string)$row->meter_color,
                    'collected_at'=>CarbonImmutable::parse((string)$row->collected_at,'UTC')->toIso8601String(),
                    'received_at'=>CarbonImmutable::parse((string)$row->received_at,'UTC')->toIso8601String(),
                ];
            });
    }
}
