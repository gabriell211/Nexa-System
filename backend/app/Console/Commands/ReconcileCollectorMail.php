<?php
declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AuditEntry;
use App\Models\CollectorIncidentNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Manual reconciliation ONLY: no inbox, SMTP, webhook or send operations.
 * Accessible only to administrators with permission to execute Artisan on
 * the backend host. Never trust unverified external bounce messages.
 */
final class ReconcileCollectorMail extends Command
{
    protected $signature='nexa:alerts:reconcile-collector-mail
        {notification_id : Exact notification database ID}
        {--type= : hard_bounce, soft_bounce or complaint}
        {--confirm-reviewed-evidence : Operator reviewed external delivery-provider evidence}';
    protected $description='Audit a manually verified Collector incident email rejection, without resending';

    public function handle(): int
    {
        $id=filter_var($this->argument('notification_id'),FILTER_VALIDATE_INT,[
            'options'=>['min_range'=>1],
        ]);
        $type=$this->option('type');
        if (!$id || !in_array($type,['hard_bounce','soft_bounce','complaint'],true)
            || $this->option('confirm-reviewed-evidence')!==true) {
            $this->error('Provide an exact positive ID, --type=hard_bounce|soft_bounce|complaint, and --confirm-reviewed-evidence.');
            return self::FAILURE;
        }

        $changed=DB::transaction(static function () use ($id,$type): bool {
            $record=CollectorIncidentNotification::query()->lockForUpdate()->find($id);
            // A provider can bounce after the transport has accepted a
            // message; cannot declare a failed or ambiguous attempt bounced.
            if ($record===null || !in_array($record->status,['accepted','delivered'],true)) return false;
            $previous=$record->status;
            $record->status=$type==='complaint'?'complaint':'returned';
            $record->error_category=$type;
            $record->save();
            AuditEntry::query()->create([
                'tenant_id'=>$record->tenant_id,
                'actor_user_id'=>null,
                'action'=>'collector.notice.delivery_reconciled',
                'entity_type'=>'CollectorIncidentNotification',
                'entity_id'=>$record->id,
                'origin'=>'manual-backend-delivery-review',
                'before_state'=>['status'=>$previous],
                'after_state'=>[
                    'status'=>$record->status,
                    'category'=>$type,
                    'stage'=>$record->stage,
                    'incident_id'=>$record->collector_health_incident_id,
                    'recipient_user_id'=>$record->recipient_user_id,
                ],
                'created_at'=>now('UTC'),
            ]);
            return true;
        },3);

        if (!$changed) {
            $this->error('Notification not found or not transport-accepted; no record changed.');
            return self::FAILURE;
        }
        $this->info('Delivery outcome recorded. No email was sent or retried.');
        return self::SUCCESS;
    }
}
