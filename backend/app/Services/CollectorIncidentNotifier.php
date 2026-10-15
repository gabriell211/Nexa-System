<?php
declare(strict_types=1);

namespace App\Services;

use App\Mail\CollectorHealthIncidentMail;
use App\Models\AuditEntry;
use App\Models\CollectorHealthIncident;
use App\Models\CollectorIncidentNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * One transport attempt per incident/recipient/stage. SMTP acceptance does
 * NOT prove mailbox delivery. Ambiguous crashes or transport failures are
 * not automatically retried, to avoid duplicate notifications.
 */
final class CollectorIncidentNotifier
{
    public function send(int $escalationMinutes): array
    {
        if ($escalationMinutes<15 || $escalationMinutes>10080) {
            throw new \InvalidArgumentException('Escalation must be 15..10080 minutes.');
        }
        $now=CarbonImmutable::now('UTC');
        $stats=['incidents'=>0,'attempted'=>0,'accepted'=>0,'failed'=>0,'skipped'=>0];
        CollectorHealthIncident::query()->where('status','active')
            ->orderBy('id')->chunkById(100,function ($incidents) use ($now,$escalationMinutes,&$stats): void {
                foreach ($incidents as $incident) {
                    $stats['incidents']++;
                    $this->dispatchStage((int)$incident->id,'initial',$now,$stats);
                    if ($incident->detected_at?->lessThanOrEqualTo($now->subMinutes($escalationMinutes))) {
                        $this->dispatchStage((int)$incident->id,'escalation',$now,$stats);
                    }
                }
            });
        return $stats;
    }

    private function dispatchStage(int $incidentId,string $stage,CarbonImmutable $now,array &$stats): void
    {
        $incident=CollectorHealthIncident::query()->with(['agent','customer'])
            ->find($incidentId);
        if ($incident===null || $incident->status!=='active') return;
        if ($incident->agent===null || !$incident->agent->active
            || !$incident->customer?->active
            || !DB::table('tenants')->where('id',$incident->tenant_id)->where('active',true)->exists()) {
            return;
        }

        // Only currently active, verified tenant administrators get mail.
        // Initial notice goes to admins/owners; escalation goes to owners.
        $recipients=DB::table('tenant_user')
            ->join('users','users.id','=','tenant_user.user_id')
            ->where('tenant_user.tenant_id',$incident->tenant_id)
            ->whereIn('tenant_user.role',$stage==='escalation'?['owner']:['owner','admin'])
            ->where('users.active',true)
            ->whereNotNull('users.email_verified_at')
            ->whereNotNull('users.email')
            ->select('users.id','users.email')->orderBy('users.id')->limit(50)->get();

        foreach ($recipients as $recipient) {
            // Lock the incident as the check is made. The unique DB index is
            // the final protection against concurrent scheduler instances.
            $noticeId=DB::transaction(function () use ($incidentId,$recipient,$stage,$now): ?int {
                $fresh=CollectorHealthIncident::query()->lockForUpdate()->find($incidentId);
                if ($fresh===null || $fresh->status!=='active') return null;
                if (CollectorIncidentNotification::query()
                    ->where('collector_health_incident_id',$incidentId)
                    ->where('recipient_user_id',$recipient->id)
                    ->where('stage',$stage)->exists()) return null;
                return (int)CollectorIncidentNotification::query()->create([
                    'tenant_id'=>$fresh->tenant_id,
                    'collector_health_incident_id'=>$incidentId,
                    'recipient_user_id'=>$recipient->id,
                    'stage'=>$stage,
                    'status'=>'attempting',
                    'attempted_at'=>$now,
                ])->id;
            },3);
            if ($noticeId===null) {
                $stats['skipped']++;
                continue;
            }

            // A concurrent heartbeat can resolve this incident between the
            // reservation and transport attempt. Avoid sending in that case.
            if (!CollectorHealthIncident::query()->whereKey($incidentId)
                ->where('status','active')->exists()) {
                $this->complete($noticeId,'skipped',null);
                $stats['skipped']++;
                continue;
            }

            $stats['attempted']++;
            try {
                $tenantName=DB::table('tenants')->where('id',$incident->tenant_id)->value('name')??'Nexa';
                Mail::to($recipient->email)->send(new CollectorHealthIncidentMail(
                    (string)$tenantName,
                    (string)$incident->customer->name,
                    (int)$incident->collector_agent_id,
                    (int)$incident->id,
                    $incident->last_heartbeat_at->toIso8601String(),
                    $incident->detected_at->toIso8601String(),
                    $stage
                ));
                $this->complete($noticeId,'accepted',$now);
                $stats['accepted']++;
            } catch (\Throwable $error) {
                // Never persist raw transport errors: SMTP exceptions can
                // include credentials, domains or recipient addresses.
                $this->complete($noticeId,'failed',null);
                $stats['failed']++;
            }
        }
    }

    private function complete(int $noticeId,string $status,?CarbonImmutable $accepted): void
    {
        DB::transaction(function () use ($noticeId,$status,$accepted): void {
            $record=CollectorIncidentNotification::query()->lockForUpdate()->findOrFail($noticeId);
            if ($record->status!=='attempting') return;
            $record->status=$status;
            $record->accepted_at=$accepted;
            $record->error_category=$status==='failed'?'transport_failure':null;
            $record->save();
            AuditEntry::query()->create([
                'tenant_id'=>$record->tenant_id,
                'actor_user_id'=>null,
                'action'=>'collector.notice.'.$status,
                'entity_type'=>'CollectorIncidentNotification',
                'entity_id'=>$record->id,
                'origin'=>'scheduled-collector-notification',
                'before_state'=>['status'=>'attempting'],
                'after_state'=>[
                    'status'=>$status,
                    'stage'=>$record->stage,
                    'incident_id'=>$record->collector_health_incident_id,
                    'recipient_user_id'=>$record->recipient_user_id,
                ],
                'created_at'=>now('UTC'),
            ]);
        },3);
    }
}
