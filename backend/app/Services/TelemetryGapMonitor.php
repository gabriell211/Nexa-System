<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\AuditEntry;
use App\Models\Printer;
use App\Models\PrinterAlert;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Reports absence of fresh meter samples, never definitive device offline status.
 * A collector can be healthy while a printer is unavailable, or vice versa.
 */
final class TelemetryGapMonitor
{
    public function check(int $gapMinutes, int $recoveryMinutes): array
    {
        if ($gapMinutes<15 || $gapMinutes>10080 || $recoveryMinutes<1 ||
            $recoveryMinutes>1440 || $recoveryMinutes>=$gapMinutes) {
            throw new \InvalidArgumentException('Invalid telemetry thresholds.');
        }

        $now=CarbonImmutable::now('UTC');
        $cutoff=$now->subMinutes($gapMinutes);
        $metrics=['checked'=>0,'opened'=>0,'resolved'=>0,'deduplicated'=>0];

        // Join an aggregate projection to avoid one query per printer (N+1).
        // Never create an incident for a device without any collected sample.
        $latest=DB::table('printer_readings')
            ->select('printer_id')
            ->selectRaw('MAX(collected_at) as last_collected_at')
            ->groupBy('printer_id');

        DB::table('printers as printers')
            ->join('customers as customers',function ($join): void {
                $join->on('customers.id','=','printers.customer_id')
                    ->on('customers.tenant_id','=','printers.tenant_id');
            })
            ->join('tenants as tenants','tenants.id','=','printers.tenant_id')
            ->joinSub($latest,'samples',function ($join): void {
                $join->on('samples.printer_id','=','printers.id');
            })
            ->where('printers.active',true)
            ->where('customers.active',true)
            ->where('tenants.active',true)
            ->where('samples.last_collected_at','<=',$cutoff)
            ->select('printers.id')
            ->orderBy('printers.id')
            ->chunkById(100,function ($rows) use ($cutoff,&$metrics): void {
                foreach ($rows as $row) {
                    $metrics['checked']++;
                    $result=$this->openIfStale((int)$row->id,$cutoff);
                    if ($result==='opened') $metrics['opened']++;
                    elseif ($result==='duplicate') $metrics['deduplicated']++;
                }
            },'printers.id','id');

        // Linked incidents require an operator to reconcile the linked ticket.
        PrinterAlert::query()->where('origin','telemetry')->where('code','TELEMETRY_GAP')
            ->whereIn('status',['active','acknowledged'])
            ->orderBy('id')
            ->chunkById(100,function ($alerts) use ($now,$gapMinutes,$recoveryMinutes,&$metrics): void {
                foreach ($alerts as $alert) {
                    if ($this->resolveIfRecovered((int)$alert->id,$now,$gapMinutes,$recoveryMinutes)) {
                        $metrics['resolved']++;
                    }
                }
            });

        return $metrics;
    }

    private function openIfStale(int $printerId,CarbonImmutable $cutoff): string
    {
        return DB::transaction(function () use ($printerId,$cutoff): string {
            // Same row-lock order as the operator incident service.
            $printer=Printer::query()->where('active',true)->lockForUpdate()
                ->find($printerId);
            if ($printer===null || !$printer->tenant()->where('active',true)->exists() ||
                !$printer->customer()->where('active',true)->exists()) return 'skip';

            $reading=DB::table('printer_readings')
                ->where('tenant_id',$printer->tenant_id)
                ->where('printer_id',$printer->id)
                ->orderByDesc('collected_at')->orderByDesc('id')->first();
            if ($reading===null ||
                CarbonImmutable::parse($reading->collected_at,'UTC')->greaterThan($cutoff)) {
                return 'skip';
            }

            $existing=PrinterAlert::query()
                ->where('tenant_id',$printer->tenant_id)
                ->where('printer_id',$printer->id)
                ->where('origin','telemetry')->where('code','TELEMETRY_GAP')
                ->where(function ($query) use ($reading): void {
                    $query->whereIn('status',['active','acknowledged','linked'])
                        ->orWhere('telemetry_anchor_id',$reading->id);
                })->first();
            if ($existing!==null) return 'duplicate';

            $alert=PrinterAlert::query()->create([
                'tenant_id'=>$printer->tenant_id,
                'customer_id'=>$printer->customer_id,
                'printer_id'=>$printer->id,
                'created_by_user_id'=>null,
                'telemetry_anchor_id'=>$reading->id,
                'origin'=>'telemetry',
                'code'=>'TELEMETRY_GAP',
                'category'=>'communication',
                'severity'=>'warning',
                'status'=>'active',
                'title'=>'Sem leituras recentes do coletor',
                'description'=>'Nenhuma leitura recente deste equipamento foi registrada. '
                    .'Isto não comprova que a impressora está offline.',
            ]);
            $this->auditSystem('alert.telemetry_gap.opened',$alert,null,[
                'status'=>'active',
                'anchor_reading_id'=>$reading->id,
                'last_collected_at'=>$reading->collected_at,
                'reason'=>'Intervalo de silêncio de coleta excedeu a tolerância configurada.',
            ]);
            return 'opened';
        },3);
    }

    private function resolveIfRecovered(
        int $alertId,CarbonImmutable $now,int $gapMinutes,int $recoveryMinutes
    ): bool {
        return DB::transaction(function () use ($alertId,$now,$gapMinutes,$recoveryMinutes): bool {
            $item=PrinterAlert::query()->find($alertId);
            if ($item===null) return false;
            $printer=Printer::query()->lockForUpdate()->find($item->printer_id);
            if ($printer===null) return false;
            $alert=PrinterAlert::query()->lockForUpdate()->find($alertId);
            if ($alert===null || !in_array($alert->status,['active','acknowledged'],true) ||
                $alert->origin!=='telemetry' || $alert->ticket_id!==null ||
                !$printer->active || !$printer->tenant()->where('active',true)->exists() ||
                !$printer->customer()->where('active',true)->exists()) return false;

            // Fresh collection timestamps must be distinct, genuinely after opening,
            // and separated by the recovery stability window.
            $samples=DB::table('printer_readings')
                ->where('tenant_id',$alert->tenant_id)->where('printer_id',$alert->printer_id)
                ->where('collected_at','>',$alert->created_at)
                ->selectRaw('COUNT(*) as sample_count, MIN(collected_at) as first_seen, MAX(collected_at) as last_seen')
                ->first();
            if (!$samples || (int)$samples->sample_count<2 ||
                $samples->first_seen===null || $samples->last_seen===null) return false;

            $first=CarbonImmutable::parse($samples->first_seen,'UTC');
            $last=CarbonImmutable::parse($samples->last_seen,'UTC');
            if ($last->lessThan($first->addMinutes($recoveryMinutes)) ||
                $last->lessThan($now->subMinutes($gapMinutes))) return false;

            $before=['status'=>$alert->status,'ticket_id'=>$alert->ticket_id];
            $alert->status='resolved';
            $alert->resolved_at=$now;
            $alert->save();
            $this->auditSystem('alert.telemetry_gap.recovered',$alert,$before,[
                'status'=>'resolved','first_recovery_sample'=>$samples->first_seen,
                'last_recovery_sample'=>$samples->last_seen,
                'reason'=>'Duas leituras novas após o alerta confirmaram retomada estável da coleta.',
            ]);
            return true;
        },3);
    }

    private function auditSystem(string $action,PrinterAlert $alert,?array $before,array $after): void
    {
        AuditEntry::query()->create([
            'tenant_id'=>$alert->tenant_id,'actor_user_id'=>null,
            'action'=>$action,'entity_type'=>'PrinterAlert','entity_id'=>$alert->id,
            'origin'=>'scheduled-telemetry-check','before_state'=>$before,
            'after_state'=>$after,'created_at'=>now('UTC'),
        ]);
    }
}
