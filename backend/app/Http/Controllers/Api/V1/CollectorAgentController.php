<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\CollectorAgent;
use App\Services\CustomerPortfolio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class CollectorAgentController extends Controller
{
    public function __construct(private readonly CustomerPortfolio $portfolio) {}

    public function __invoke(Request $request): JsonResponse
    {
        $filters=$request->validate([
            'page'=>['sometimes','integer','min:1'],
            'per_page'=>['sometimes','integer','between:1,100'],
            'customer_id'=>['sometimes','integer','min:1'],
        ]);
        $tenant=$request->attributes->get('nexa_tenant');
        $now=now('UTC');
        $threshold=(int)config('nexa.collector_heartbeat.max_silence_seconds',180);
        $threshold=max(60,min(86400,$threshold));

        $query=CollectorAgent::query()->where('tenant_id',$tenant->id)
            ->with('customer:id,name')
            ->when(isset($filters['customer_id']),fn($q)=>$q->where('customer_id',$filters['customer_id']));
        if (!$this->portfolio->hasFullAccess($request)) {
            $query->whereIn('customer_id',$this->portfolio->assignedIds($request));
        }

        $page=$query->orderByDesc('id')->paginate($filters['per_page']??25);

        return response()->json($page->through(static function (CollectorAgent $agent) use ($now,$threshold): array {
            $heartbeat=$agent->heartbeat_at;
            $state=!$agent->active ? 'revoked'
                : ($heartbeat===null ? 'no_heartbeat'
                : ($heartbeat->greaterThanOrEqualTo($now->copy()->subSeconds($threshold))
                    ? 'recent' : 'late'));
            return [
                'id'=>$agent->id,
                'collector_id'=>$agent->collector_id,
                'customer_id'=>$agent->customer_id,
                'customer'=>$agent->customer?->only(['id','name']),
                'active'=>(bool)$agent->active,
                'heartbeat_at'=>$heartbeat?->toIso8601String(),
                'last_upload_at'=>$agent->last_seen_at?->toIso8601String(),
                'heartbeat_state'=>$state,
                'heartbeat_tolerance_seconds'=>$threshold,
            ];
        }));
    }
}
