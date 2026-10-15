<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\AuditEntry;
use App\Models\CollectorIncidentNotification;
use App\Models\CollectorNotificationReplay;
use Illuminate\Support\Facades\DB;

final class ResendWebhookProcessor
{
    public function verify(string $body,?string $id,?string $timestamp,?string $signature): bool
    {
        $secret=config('nexa.collector_notifications.resend_webhook_secret');
        if (!is_string($secret) || !str_starts_with($secret,'whsec_')
            || strlen($body)>65536 || !is_string($id) || !is_string($timestamp)
            || !is_string($signature) || strlen($signature)>2048
            || !preg_match('/^[a-zA-Z0-9_-]{1,128}$/D',$id)
            || !preg_match('/^[1-9][0-9]{8,11}$/D',$timestamp)
            || abs(time()-(int)$timestamp)>300) {
            return false;
        }
        $key=base64_decode(substr($secret,6),true);
        if (!is_string($key) || strlen($key)<16) return false;

        $mac=base64_encode(hash_hmac('sha256',$id.'.'.$timestamp.'.'.$body,$key,true));
        foreach (preg_split('/\s+/',trim($signature)) as $entry) {
            if (str_starts_with($entry,'v1,')
                && hash_equals($mac,substr($entry,3))) return true;
        }
        return false;
    }

    /**
     * Must only be called AFTER successful signature verification. Unknown
     * provider UUIDs are acknowledged but never correlated by email address.
     * An event ID can be processed once (unique SHA-256), even under concurrent
     * deliveries from a webhook provider.
     */
    public function ingest(string $body,string $svixId): string
    {
        $event=json_decode($body,true,32);
        if (!is_array($event) || !is_string($event['type']??null)) return 'invalid';
        $type=$event['type'];
        if (!in_array($type,['email.delivered','email.bounced','email.complained'],true)) {
            $type='ignored';
        }
        $emailId=$event['data']['email_id']??null;
        if ($type!=='ignored' && (!is_string($emailId)
            || !preg_match('/^[a-fA-F0-9-]{36}$/D',$emailId))) return 'invalid';

        return DB::transaction(function () use ($type,$event,$emailId,$svixId): string {
            $hash=hash('sha256','resend:'.$svixId);
            $now=now('UTC');
            // Store only a hash of provider webhook IDs and a safe category:
            // no raw payload, recipient, subject or bounce message.
            $inserted=DB::table('collector_mail_webhook_events')->insertOrIgnore([
                'event_hash'=>$hash,
                'kind'=>$type,
                'outcome'=>'ignored',
                'received_at'=>$now,
            ]);
            if ($inserted===0) return 'duplicate';
            if ($type==='ignored') return 'ignored';

            $id=strtolower($emailId);
            $original=CollectorIncidentNotification::query()
                ->where('provider_email_id',$id)->lockForUpdate()->first();
            $replay=CollectorNotificationReplay::query()
                ->where('provider_email_id',$id)->lockForUpdate()->first();
            // Fail closed on an impossible cross-table duplicate identity.
            if (($original!==null && $replay!==null)
                || ($original===null && $replay===null)) {
                DB::table('collector_mail_webhook_events')
                    ->where('event_hash',$hash)->update(['outcome'=>'unmatched']);
                return 'unmatched';
            }
            $record=$original??$replay;
            $target=$original!==null?'original':'replay';
            $changed=false;
            $next=null;
            if ($type==='email.complained' && in_array($record->status,['accepted','delivered','returned'],true)) {
                $next='complaint';
            } elseif ($type==='email.bounced' && in_array($record->status,['accepted','delivered'],true)) {
                $next='returned';
            } elseif ($type==='email.delivered' && $record->status==='accepted') {
                $next='delivered';
            }
            if ($next!==null) {
                $previous=$record->status;
                $record->status=$next;
                if ($next==='delivered') {
                    $record->provider_delivered_at=$now;
                    $record->error_category=null;
                } elseif ($next==='returned') {
                    $bounceType=$event['data']['bounce']['type']??null;
                    $record->error_category=$bounceType==='Permanent'?'hard_bounce'
                        : ($bounceType==='Transient'?'soft_bounce':'bounce');
                } else {
                    $record->error_category='complaint';
                }
                $record->save();
                AuditEntry::query()->create([
                    'tenant_id'=>$record->tenant_id,
                    'actor_user_id'=>null,
                    'action'=>'collector.notice.provider_'.$next,
                    'entity_type'=>$target==='original'?'CollectorIncidentNotification':'CollectorNotificationReplay',
                    'entity_id'=>$record->id,
                    'origin'=>'verified-resend-webhook',
                    'before_state'=>['status'=>$previous],
                    'after_state'=>['status'=>$next,'kind'=>$type],
                    'created_at'=>$now,
                ]);
                $changed=true;
            }
            DB::table('collector_mail_webhook_events')->where('event_hash',$hash)->update([
                'outcome'=>$changed?'applied':'stale',
                'target_type'=>$target,
                'target_id'=>$record->id,
            ]);
            return $changed?'applied':'stale';
        },3);
    }
}
