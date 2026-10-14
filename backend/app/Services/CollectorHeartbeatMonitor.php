<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\AuditEntry;
use App\Models\CollectorAgent;
use App\Models\CollectorHealthIncident;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Detects server-observed heartbeat silence (not a printer hardware outage).
 * A successful upload does not imply that the heartbeat worker is running.
 */
final class CollectorHeartbeatMonitor
{
    public function check(int $silenceSeconds): array
    {
        if ($silenceSeconds<60 || $silenceSeconds>86400) {
            throw new \InvalidArgumentException('Collector heartbeat silence must be 60..86400 seconds.');
        }

        $now=CarbonImmutable::now('UTC');
        $cutoff=$now->subSeconds($silenceSeconds);
        $counts=['checked'=>0,'opened'=>0,'deduplicated'=>0,'recovered'=>0,'closed'=>0];

        // Avoid one alert per never-connected agent: the installed service may
        // not yet have been provisioned or started. Other failures have separate
        // operational diagnostics.
        DB::table('collector_agents as agents')
            ->join('customers as customers',function ($join): void {
                $join->on('customers.id','=','agents.customer_id')
                    ->on('customers.tenant_id','=','agents.tenant_id');
            })
            ->join('tenants as tenants','tenants.id','=','agents.tenant_id')
            ->where('agents.active',true)
            ->where('customers.active',true)
            ->where('tenants.active',true)
            ->whereNotNull('agents.heartbeat_at')
            ->where('agents.heartbeat_at','<',$cutoff)
            ->select('agents.id as id')->orderBy('agents.id')
            ->chunkById(100,function ($rows) use ($cutoff,$now,&$counts): void {
                foreach ($rows as $row) {
                    $counts['checked']++;
                    $result=$this->openIfLate((int)$row->id,$cutoff,$now);
                    if ($result==='opened') $counts['opened']++;
                    elseif ($result==='duplicate') $counts['deduplicated']++;
                }
            },'agents.id','id');

        CollectorHealthIncident::query()->where('status','active')
            ->orderBy('id')->chunkById(100,function ($alerts) use ($cutoff,$now,&$counts): void {
                foreach ($alerts as $alert) {
                    $result=$this->closeIfRecoveredOrDeactivated((int)$alert->id,$cutoff,$now);
                    if ($result==='recovered') $counts['recovered']++;
                    elseif ($result==='closed') $counts['closed']++;
                }
            });

        return $counts;
    }

    private function openIfLate(int $agentId, CarbonImmutable $cutoff, CarbonImmutable $now): string
    {
        return DB::transaction(function () use ($agentId,$cutoff,$now): string {
            $agent=CollectorAgent::query()->lockForUpdate()->find($agentId);
            if ($agent===null || !$agent->active || $agent->heartbeat_at===null
                || !$agent->heartbeat_at->lessThan($cutoff)
                || !$agent->customer()->where('active',true)->exists()
                || !DB::table('tenants')->where('id',$agent->tenant_id)->where('active',true)->exists()) {
                return 'skip';
            }

            if (CollectorHealthIncident::query()
                ->where('collector_agent_id',$agent->id)
                ->where('code','COLLECTOR_HEARTBEAT_LATE')
                ->where('status','active')->exists()) {
                return 'duplicate';
            }
            $incident=CollectorHealthIncident::query()->create([
                'tenant_id'=>$agent->tenant_id,
                'customer_id'=>$agent->customer_id,
                'collector_agent_id'=>$agent->id,
                'code'=>'COLLECTOR_HEARTBEAT_LATE',
                'status'=>'active',
                'last_heartbeat_at'=>$agent->heartbeat_at,
                'detected_at'=>$now,
            ]);
            $this->audit($incident,'collector.heartbeat_late.opened',[
                'last_heartbeat_at'=>$agent->heartbeat_at->toIso8601String(),
                'reason'=>'No authenticated heartbeat received within configured tolerance.',
            ]);
            return 'opened';
        },3);
    }

    private function closeIfRecoveredOrDeactivated(
        int $incidentId,CarbonImmutable $cutoff,CarbonImmutable $now
    ): string {
        return DB::transaction(function () use ($incidentId,$cutoff,$now): string {
            $incident=CollectorHealthIncident::query()->find($incidentId);
            if ($incident===null || $incident->status!=='active') return 'skip';
            // Lock order is the same as for opening: agent then incident.
            $agent=CollectorAgent::query()->lockForUpdate()->find($incident->collector_agent_id);
            $incident=CollectorHealthIncident::query()->lockForUpdate()->find($incidentId);
            if ($incident===null || $incident->status!=='active') return 'skip';

            $active=$agent!==null && $agent->active
                && $agent->customer()->where('active',true)->exists()
                && DB::table('tenants')->where('id',$agent->tenant_id)->where('active',true)->exists();
            $resumed=$active && $agent->heartbeat_at!==null
                && $agent->heartbeat_at->greaterThanOrEqualTo($cutoff)
                && $agent->heartbeat_at->greaterThan($incident->detected_at);
            if (!$active && $agent!==null) {
                $reason='deactivated';
                $status='closed';
            } elseif ($resumed) {
                $reason='heartbeat_resumed';
                $status='resolved';
            } else {
                return 'skip';
            }
            $incident->status=$status;
            $incident->resolved_at=$now;
            $incident->resolution_reason=$reason;
            $incident->save();
            $this->audit($incident,'collector.heartbeat_late.'.$status,[
                'resolution_reason'=>$reason,
                'heartbeat_at'=>$agent?->heartbeat_at?->toIso8601String(),
            ]);
            return $status==='resolved' ? 'recovered' : 'closed';
        },3);
    }

    private function audit(CollectorHealthIncident $incident,string $action,array $data): void
    {
        AuditEntry::query()->create([
            'tenant_id'=>$incident->tenant_id,
            'actor_user_id'=>null,
            'action'=>$action,
            'entity_type'=>'CollectorHealthIncident',
            'entity_id'=>$incident->id,
            'origin'=>'scheduled-heartbeat-check',
            'before_state'=>null,
            'after_state'=>$data,
            'created_at'=>now('UTC'),
        ]);
    }
}
