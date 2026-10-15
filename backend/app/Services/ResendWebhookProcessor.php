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
     * Requires a verified Svix signature from the controller. Event identity
     * and provider UUID are the only retained identifiers; no email addresses
     * or untrusted event bodies are persisted.
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
        $bounce=$event['data']['bounce']['type']??null;
        $category=$type==='email.bounced'
            ? ($bounce==='Permanent'?'hard_bounce':($bounce==='Transient'?'soft_bounce':'bounce'))
            : null;

        return DB::transaction(function () use ($type,$emailId,$svixId,$category): string {
            $hash=hash('sha256','resend:'.$svixId);
            $inserted=DB::table('collector_mail_webhook_events')->insertOrIgnore([
                'event_hash'=>$hash,
                'kind'=>$type,
                'outcome'=>$type==='ignored'?'ignored':'pending',
                'provider_email_id'=>$type==='ignored'?null:strtolower($emailId),
                'bounce_category'=>$category,
                'received_at'=>now('UTC'),
            ]);
            if ($inserted===0) return 'duplicate';
            if ($type==='ignored') return 'ignored';
            return $this->applyPendingLocked($hash);
        },3);
    }

    /**
     * Fast path after saving the provider message UUID from the outbound API.
     * Its DB transaction has committed before this is called.
     */
    public function reconcileForProviderEmailId(string $emailId): array
    {
        if (!preg_match('/^[a-fA-F0-9-]{36}$/D',$emailId)) return [];
        $hashes=DB::table('collector_mail_webhook_events')
            ->where('provider_email_id',strtolower($emailId))
            ->where('outcome','pending')->orderBy('id')->limit(100)
            ->pluck('event_hash');
        $results=[];
        foreach ($hashes as $hash) {
            $results[]=$this->applyPending($hash);
        }
        return $results;
    }

    /**
     * Background repair for races where webhook insertion and sender commit
     * overlap. Only pick events with an already-known UUID, so unrelated
     * provider messages cannot starve the bounded reconciliation batch.
     * Old unmatched events expire but keep their event hashes, preventing
     * a late duplicate from being treated as a fresh notification.
     */
    public function reconcilePending(int $limit=100,int $maxAgeHours=72): array
    {
        if ($limit<1 || $limit>500 || $maxAgeHours<1 || $maxAgeHours>168) {
            throw new \InvalidArgumentException('Invalid provider reconciliation bounds.');
        }
        $expired=DB::table('collector_mail_webhook_events')
            ->where('outcome','pending')
            ->where('received_at','<',now('UTC')->subHours($maxAgeHours))
            ->update(['outcome'=>'expired']);

        $hashes=DB::table('collector_mail_webhook_events')
            ->where('outcome','pending')
            ->where(function ($query): void {
                $query->whereExists(function ($sub): void {
                    $sub->selectRaw('1')->from('collector_incident_notifications as notices')
                        ->whereColumn('notices.provider_email_id','collector_mail_webhook_events.provider_email_id');
                })->orWhereExists(function ($sub): void {
                    $sub->selectRaw('1')->from('collector_notification_replays as replays')
                        ->whereColumn('replays.provider_email_id','collector_mail_webhook_events.provider_email_id');
                });
            })
            ->orderBy('id')->limit($limit)->pluck('event_hash');

        $results=['matched'=>0,'applied'=>0,'stale'=>0,'expired'=>$expired];
        foreach ($hashes as $hash) {
            $outcome=$this->applyPending($hash);
            if ($outcome==='applied' || $outcome==='stale') {
                $results['matched']++;
                $results[$outcome]++;
            }
        }
        return $results;
    }

    private function applyPending(string $hash): string
    {
        return DB::transaction(fn(): string=>$this->applyPendingLocked($hash),3);
    }

    // Must run in DB::transaction and first lock the event row.
    private function applyPendingLocked(string $hash): string
    {
        $event=DB::table('collector_mail_webhook_events')
            ->where('event_hash',$hash)->lockForUpdate()->first();
        if ($event===null || $event->outcome!=='pending') return 'duplicate';

        $original=CollectorIncidentNotification::query()
            ->where('provider_email_id',$event->provider_email_id)->lockForUpdate()->first();
        $replay=CollectorNotificationReplay::query()
            ->where('provider_email_id',$event->provider_email_id)->lockForUpdate()->first();
        if (($original===null && $replay===null) || ($original!==null && $replay!==null)) {
            // Remain pending until matched/expired; do not guess the tenant
            // based on recipient, subject or identifiers from the webhook.
            return 'unmatched';
        }
        $record=$original??$replay;
        $target=$original!==null?'original':'replay';
        $previous=$record->status;
        $next=null;
        if ($event->kind==='email.complained' && in_array($previous,['accepted','delivered','returned'],true)) {
            $next='complaint';
        } elseif ($event->kind==='email.bounced' && in_array($previous,['accepted','delivered'],true)) {
            $next='returned';
        } elseif ($event->kind==='email.delivered' && $previous==='accepted') {
            $next='delivered';
        }

        if ($next!==null) {
            $record->status=$next;
            if ($next==='delivered') {
                $record->provider_delivered_at=now('UTC');
                $record->error_category=null;
            } elseif ($next==='returned') {
                $record->error_category=$event->bounce_category??'bounce';
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
                'after_state'=>['status'=>$next,'kind'=>$event->kind],
                'created_at'=>now('UTC'),
            ]);
        }
        DB::table('collector_mail_webhook_events')->where('event_hash',$hash)->update([
            'outcome'=>$next!==null?'applied':'stale',
            'target_type'=>$target,
            'target_id'=>$record->id,
        ]);
        return $next!==null?'applied':'stale';
    }
}
