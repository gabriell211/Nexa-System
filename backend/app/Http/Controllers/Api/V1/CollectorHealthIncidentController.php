<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\CollectorHealthIncident;
use App\Services\CustomerPortfolio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class CollectorHealthIncidentController extends Controller
{
    public function __construct(private readonly CustomerPortfolio $portfolio) {}

    public function __invoke(Request $request): JsonResponse
    {
        $filters=$request->validate([
            'status'=>['sometimes','in:active,resolved,closed'],
            'collector_agent_id'=>['sometimes','integer','min:1'],
            'customer_id'=>['sometimes','integer','min:1'],
            'page'=>['sometimes','integer','min:1'],
            'per_page'=>['sometimes','integer','between:1,100'],
        ]);
        $tenant=$request->attributes->get('nexa_tenant');
        $query=CollectorHealthIncident::query()
            ->where('tenant_id',$tenant->id)
            ->with(['agent:id,collector_id,customer_id','customer:id,name'])
            ->withCount([
                'notifications as email_accepted_count'=>fn($q)=>$q->whereIn('status',['accepted','delivered']),
                'notifications as email_provider_delivered_count'=>fn($q)=>$q->where('status','delivered'),
                'notifications as email_failed_count'=>fn($q)=>$q->whereIn('status',['failed','attempting']),
                'notifications as email_returned_count'=>fn($q)=>$q->where('status','returned'),
                'notifications as email_complaint_count'=>fn($q)=>$q->where('status','complaint'),
                'notificationReplays as email_replay_accepted_count'=>fn($q)=>$q->whereIn('status',['accepted','delivered']),
                'notificationReplays as email_replay_delivered_count'=>fn($q)=>$q->where('status','delivered'),
                'notificationReplays as email_replay_unconfirmed_count'=>fn($q)=>$q->whereIn('status',['attempting','failed']),
            ])
            ->when(isset($filters['status']),fn($q)=>$q->where('status',$filters['status']))
            ->when(isset($filters['collector_agent_id']),fn($q)=>$q->where('collector_agent_id',$filters['collector_agent_id']))
            ->when(isset($filters['customer_id']),fn($q)=>$q->where('customer_id',$filters['customer_id']));
        if (!$this->portfolio->hasFullAccess($request)) {
            $query->whereIn('customer_id',$this->portfolio->assignedIds($request));
        }
        $page=$query->orderByDesc('detected_at')->orderByDesc('id')
            ->paginate($filters['per_page']??25);
        return response()->json($page->through(static function (CollectorHealthIncident $incident): array {
            return [
                'id'=>$incident->id,
                'collector_agent_id'=>$incident->collector_agent_id,
                'collector_id'=>$incident->agent?->collector_id,
                'customer_id'=>$incident->customer_id,
                'customer'=>$incident->customer?->only(['id','name']),
                'code'=>$incident->code,
                'status'=>$incident->status,
                'last_heartbeat_at'=>$incident->last_heartbeat_at?->toIso8601String(),
                'detected_at'=>$incident->detected_at?->toIso8601String(),
                'resolved_at'=>$incident->resolved_at?->toIso8601String(),
                'resolution_reason'=>$incident->resolution_reason,
                'email_accepted_count'=>(int)$incident->email_accepted_count,
                'email_provider_delivered_count'=>(int)$incident->email_provider_delivered_count,
                'email_unconfirmed_count'=>(int)$incident->email_failed_count,
                'email_returned_count'=>(int)$incident->email_returned_count,
                'email_complaint_count'=>(int)$incident->email_complaint_count,
                'email_replay_accepted_count'=>(int)$incident->email_replay_accepted_count,
                'email_replay_delivered_count'=>(int)$incident->email_replay_delivered_count,
                'email_replay_unconfirmed_count'=>(int)$incident->email_replay_unconfirmed_count,
            ];
        }));
    }
}
