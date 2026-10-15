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
    public function send(
        int $escalationMinutes,
        int $maxPerRun=50,
        int $maxPerTenantHour=20,
        int $maxGlobalHour=100
    ): array {
        if ($escalationMinutes<15 || $escalationMinutes>10080) {
            throw new \InvalidArgumentException('Escalation must be 15..10080 minutes.');
        }
        if ($maxPerRun<1 || $maxPerRun>500
            || $maxPerTenantHour<1 || $maxPerTenantHour>1000
            || $maxGlobalHour<1 || $maxGlobalHour>10000
            || $maxPerTenantHour>$maxGlobalHour) {
            throw new \InvalidArgumentException('Invalid mail budgets; per-tenant budget cannot exceed global hourly budget.');
        }
        $now=CarbonImmutable::now('UTC');
        $stats=['incidents'=>0,'attempted'=>0,'accepted'=>0,'failed'=>0,'skipped'=>0,'deferred'=>0];
        CollectorHealthIncident::query()->where('status','active')
            ->orderBy('id')->chunkById(100,function ($incidents) use ($now,$escalationMinutes,$maxPerRun,$maxPerTenantHour,$maxGlobalHour,&$stats): void {
                foreach ($incidents as $incident) {
                    $stats['incidents']++;
                    $this->dispatchStage((int)$incident->id,'initial',$now,$maxPerRun,$maxPerTenantHour,$maxGlobalHour,$stats);
                    if ($incident->detected_at?->lessThanOrEqualTo($now->subMinutes($escalationMinutes))) {
                        $this->dispatchStage((int)$incident->id,'escalation',$now,$maxPerRun,$maxPerTenantHour,$maxGlobalHour,$stats);
                    }
                }
            });
        return $stats;
    }

    private function dispatchStage(
        int $incidentId,string $stage,CarbonImmutable $now,
        int $maxPerRun,int $maxPerTenantHour,int $maxGlobalHour,array &$stats
    ): void
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
            if ($stats['attempted'] >= $maxPerRun) {
                $stats['deferred']++;
                continue;
            }
            // Lock order: global budget row -> incident. All senders take the
            // same cross-tenant row lock before checking the persisted hourly
            // reservation count. No external mail is sent inside a DB lock.
            $reservation=DB::transaction(function () use (
                $incidentId,$recipient,$stage,$now,$maxPerTenantHour,$maxGlobalHour
            ): array {
                $lock=DB::table('collector_notification_rate_locks')
                    ->where('id',1)->lockForUpdate()->first();
                if ($lock===null) {
                    throw new \RuntimeException('Collector mail budget lock missing; run migrations.');
                }
                $fresh=CollectorHealthIncident::query()->lockForUpdate()->find($incidentId);
                if ($fresh===null || $fresh->status!=='active') return ['kind'=>'skip'];
                if (!$fresh->agent()->where('active',true)->exists()
                    || !$fresh->customer()->where('active',true)->exists()
                    || !DB::table('tenants')->where('id',$fresh->tenant_id)
                        ->where('active',true)->exists()) {
                    return ['kind'=>'skip'];
                }
                // Membership, verification and current address must still be
                // valid at reservation time (not just when batching begins).
                $currentRecipient=DB::table('tenant_user')
                    ->join('users','users.id','=','tenant_user.user_id')
                    ->where('tenant_user.tenant_id',$fresh->tenant_id)
                    ->where('users.id',$recipient->id)
                    ->whereIn('tenant_user.role',$stage==='escalation'?['owner']:['owner','admin'])
                    ->where('users.active',true)
                    ->whereNotNull('users.email_verified_at')
                    ->whereNotNull('users.email')
                    ->select('users.email')->first();
                if ($currentRecipient===null) return ['kind'=>'skip'];
                if (CollectorIncidentNotification::query()
                    ->where('collector_health_incident_id',$incidentId)
                    ->where('recipient_user_id',$recipient->id)
                    ->where('stage',$stage)->exists()) return ['kind'=>'skip'];

                $since=$now->subHour();
                $global=CollectorIncidentNotification::query()
                    ->where('attempted_at','>',$since)->count();
                $tenant=CollectorIncidentNotification::query()
                    ->where('tenant_id',$fresh->tenant_id)
                    ->where('attempted_at','>',$since)->count();
                // Explicit operator-approved replays must consume the SAME
                // rolling-hour budget as automatic sends. Both hold the same
                // global row lock while checking and reserving.
                $global+=DB::table('collector_notification_replays')
                    ->where('attempted_at','>',$since)->count();
                $tenant+=DB::table('collector_notification_replays')
                    ->where('tenant_id',$fresh->tenant_id)
                    ->where('attempted_at','>',$since)->count();
                // Includes failed/ambiguous/skipped reservations: strict
                // conservative admission, never exceeding configured quotas.
                if ($global >= $maxGlobalHour || $tenant >= $maxPerTenantHour) {
                    return ['kind'=>'deferred'];
                }
                $notification=CollectorIncidentNotification::query()->create([
                    'tenant_id'=>$fresh->tenant_id,
                    'collector_health_incident_id'=>$incidentId,
                    'recipient_user_id'=>$recipient->id,
                    'stage'=>$stage,
                    'status'=>'attempting',
                    'attempted_at'=>$now,
                ]);
                return ['kind'=>'reserved','id'=>(int)$notification->id,'email'=>$currentRecipient->email];
            },3);
            if ($reservation['kind']==='skip') {
                $stats['skipped']++;
                continue;
            }
            if ($reservation['kind']==='deferred') {
                // Do not write a notification row: the next scheduler tick
                // may retry once space opens in the sliding budget window.
                $stats['deferred']++;
                continue;
            }
            $noticeId=$reservation['id'];

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
                $providerId=app(CollectorMailTransport::class)->send(
                    $reservation['email'],new CollectorHealthIncidentMail(
                    (string)$tenantName,
                    (string)$incident->customer->name,
                    (int)$incident->collector_agent_id,
                    (int)$incident->id,
                    $incident->last_heartbeat_at->toIso8601String(),
                    $incident->detected_at->toIso8601String(),
                    $stage
                ),'nexa-collector-notice-'.$noticeId);
                $this->complete($noticeId,'accepted',$now,$providerId);
                if ($providerId!==null) {
                    try {
                        app(ResendWebhookProcessor::class)->reconcileForProviderEmailId($providerId);
                     catch (\Throwable $error) {
                        // A scheduled reconciliation sweep repairs transient DB races.
                        logger()->warning('Collector provider event reconciliation deferred');
                    }
                }
                $stats['accepted']++;
            } catch (\Throwable $error) {
                // Never persist raw transport errors: SMTP exceptions can
                // include credentials, domains or recipient addresses.
                $this->complete($noticeId,'failed',null);
                $stats['failed']++;
            }
        }
    }

    private function complete(int $noticeId,string $status,?CarbonImmutable $accepted,?string $providerId=null): void
    {
        DB::transaction(function () use ($noticeId,$status,$accepted,$providerId): void {
            $record=CollectorIncidentNotification::query()->lockForUpdate()->findOrFail($noticeId);
            if ($record->status!=='attempting') return;
            $record->status=$status;
            $record->accepted_at=$accepted;
            $record->provider_email_id=$providerId;
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
