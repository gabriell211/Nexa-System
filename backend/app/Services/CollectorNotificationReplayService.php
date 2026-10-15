<?php
declare(strict_types=1);

namespace App\Services;

use App\Mail\CollectorHealthIncidentMail;
use App\Models\AuditEntry;
use App\Models\CollectorIncidentNotification;
use App\Models\CollectorNotificationReplay;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Explicit one-time replay of a REVIEWED transport failure. It is never
 * scheduled and cannot replay accepted, bounced, complained-about, skipped
 * or ambiguous ("attempting") deliveries. A failure may be ambiguous even
 * when recorded as failed; evidence is the operator's responsibility.
 */
final class CollectorNotificationReplayService
{
    public function replay(
        int $notificationId,string $reviewReference,
        int $maxPerTenantHour,int $maxGlobalHour
    ): string {
        if ($notificationId<1
            || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{3,63}$/D',$reviewReference)) {
            return 'invalid';
        }
        if ($maxPerTenantHour<1 || $maxPerTenantHour>1000
            || $maxGlobalHour<1 || $maxGlobalHour>10000
            || $maxPerTenantHour>$maxGlobalHour) {
            return 'invalid';
        }

        $now=CarbonImmutable::now('UTC');
        $reservation=DB::transaction(function () use (
            $notificationId,$reviewReference,$now,$maxPerTenantHour,$maxGlobalHour
        ): array {
            // Same global mutex and lock ordering as the scheduled sender.
            // Replay reservations consume exactly the same rolling budget.
            $mutex=DB::table('collector_notification_rate_locks')
                ->where('id',1)->lockForUpdate()->first();
            if ($mutex===null) {
                throw new \RuntimeException('Collector notification rate lock missing: migrate database first.');
            }
            $original=CollectorIncidentNotification::query()
                ->lockForUpdate()->find($notificationId);
            if ($original===null || $original->status!=='failed') {
                return ['result'=>'ineligible'];
            }
            if (CollectorNotificationReplay::query()
                ->where('original_notification_id',$notificationId)->exists()) {
                return ['result'=>'ineligible'];
            }
            $incident=$original->incident()->lockForUpdate()->first();
            if ($incident===null || $incident->status!=='active'
                || (int)$incident->tenant_id!==(int)$original->tenant_id
                || !$incident->agent()->where('active',true)->exists()
                || !$incident->customer()->where('active',true)->exists()
                || !DB::table('tenants')->where('id',$incident->tenant_id)
                    ->where('active',true)->exists()) {
                return ['result'=>'ineligible'];
            }
            $recipient=DB::table('tenant_user')
                ->join('users','users.id','=','tenant_user.user_id')
                ->where('tenant_user.tenant_id',$incident->tenant_id)
                ->where('users.id',$original->recipient_user_id)
                ->whereIn('tenant_user.role',$original->stage==='escalation'?['owner']:['owner','admin'])
                ->where('users.active',true)
                ->whereNotNull('users.email_verified_at')
                ->whereNotNull('users.email')
                ->select('users.email')->first();
            if ($recipient===null || !in_array($original->stage,['initial','escalation'],true)) {
                return ['result'=>'ineligible'];
            }
            $since=$now->subHour();
            $globalOriginal=CollectorIncidentNotification::query()
                ->where('attempted_at','>',$since)->count();
            $tenantOriginal=CollectorIncidentNotification::query()
                ->where('tenant_id',$incident->tenant_id)
                ->where('attempted_at','>',$since)->count();
            $globalReplays=CollectorNotificationReplay::query()
                ->where('attempted_at','>',$since)->count();
            $tenantReplays=CollectorNotificationReplay::query()
                ->where('tenant_id',$incident->tenant_id)
                ->where('attempted_at','>',$since)->count();
            if ($globalOriginal+$globalReplays >= $maxGlobalHour
                || $tenantOriginal+$tenantReplays >= $maxPerTenantHour) {
                return ['result'=>'deferred'];
            }
            $record=CollectorNotificationReplay::query()->create([
                'tenant_id'=>$incident->tenant_id,
                'collector_health_incident_id'=>$incident->id,
                'original_notification_id'=>$original->id,
                'review_reference'=>$reviewReference,
                'status'=>'attempting',
                'attempted_at'=>$now,
            ]);
            AuditEntry::query()->create([
                'tenant_id'=>$incident->tenant_id,
                'actor_user_id'=>null,
                'action'=>'collector.notice.replay_authorized',
                'entity_type'=>'CollectorNotificationReplay',
                'entity_id'=>$record->id,
                'origin'=>'manual-backend-provider-review',
                'before_state'=>null,
                'after_state'=>[
                    'status'=>'attempting',
                    'original_notification_id'=>$original->id,
                    'incident_id'=>$incident->id,
                    'review_reference'=>$reviewReference,
                ],
                'created_at'=>$now,
            ]);
            return [
                'result'=>'reserved',
                'replay_id'=>$record->id,
                'incident_id'=>$incident->id,
                'recipient_id'=>$original->recipient_user_id,
                'stage'=>$original->stage,
                'email'=>$recipient->email,
            ];
        },3);

        if ($reservation['result']!=='reserved') {
            return $reservation['result'];
        }
        // Never hold a database transaction during external mail transport.
        // Recheck state: recovering the incident after reservation skips mail.
        $incident=DB::table('collector_health_incidents')
            ->where('id',$reservation['incident_id'])
            ->where('status','active')->first();
        if ($incident===null) {
            $this->finish((int)$reservation['replay_id'],'skipped',null,$now);
            return 'skipped';
        }
        $customer=DB::table('customers')
            ->where('id',$incident->customer_id)->where('active',true)->first();
        $tenant=DB::table('tenants')
            ->where('id',$incident->tenant_id)->where('active',true)->first();
        if ($tenant===null || $customer===null) {
            $this->finish((int)$reservation['replay_id'],'skipped',null,$now);
            return 'skipped';
        }

        try {
            $providerId=app(CollectorMailTransport::class)->send(
                $reservation['email'],new CollectorHealthIncidentMail(
                (string)$tenant->name,
                (string)$customer->name,
                (int)$incident->collector_agent_id,
                (int)$incident->id,
                CarbonImmutable::parse($incident->last_heartbeat_at)->toIso8601String(),
                CarbonImmutable::parse($incident->detected_at)->toIso8601String(),
                $reservation['stage']
            ),'nexa-collector-replay-'.$reservation['replay_id']);
            $this->finish((int)$reservation['replay_id'],'accepted',null,$now,$providerId);
            if ($providerId!==null) {
                try {
                    app(ResendWebhookProcessor::class)->reconcileForProviderEmailId($providerId);
                } catch (\Throwable $error) {
                    // A scheduled reconciliation sweep repairs transient DB races.
                    logger()->warning('Collector provider event reconciliation deferred');
                }
            }
            return 'accepted';
        } catch (\Throwable $error) {
            // Never log an SMTP exception, recipient address, or credentials.
            $this->finish((int)$reservation['replay_id'],'failed','transport_failure',$now);
            return 'failed';
        }
    }

    private function finish(int $id,string $status,?string $category,CarbonImmutable $now,?string $providerId=null): void
    {
        DB::transaction(static function () use ($id,$status,$category,$now,$providerId): void {
            $record=CollectorNotificationReplay::query()->lockForUpdate()->findOrFail($id);
            if ($record->status!=='attempting') return;
            $record->status=$status;
            $record->accepted_at=$status==='accepted'?$now:null;
            $record->provider_email_id=$providerId;
            $record->error_category=$category;
            $record->save();
            AuditEntry::query()->create([
                'tenant_id'=>$record->tenant_id,
                'actor_user_id'=>null,
                'action'=>'collector.notice.replay_'.$status,
                'entity_type'=>'CollectorNotificationReplay',
                'entity_id'=>$record->id,
                'origin'=>'manual-backend-provider-review',
                'before_state'=>['status'=>'attempting'],
                'after_state'=>[
                    'status'=>$status,
                    'original_notification_id'=>$record->original_notification_id,
                ],
                'created_at'=>$now,
            ]);
        },3);
    }
}
