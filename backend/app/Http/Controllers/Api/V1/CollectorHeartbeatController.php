<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\CollectorHeartbeatRequest;
use App\Services\CollectorAuthenticator;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

final class CollectorHeartbeatController extends Controller
{
    public function __construct(private readonly CollectorAuthenticator $authenticator) {}

    public function __invoke(CollectorHeartbeatRequest $request): JsonResponse
    {
        $agent=$this->authenticator->authenticate(
            $request,$request->validated('collector_id')
        );

        // Only the server clock is authoritative. Never accept a client-supplied
        // timestamp or infer connectivity from SNMP/meters.
        $now=now('UTC');
        DB::table('collector_agents')
            ->where('id',$agent->id)->where('active',true)->update([
                'heartbeat_at'=>$now,'updated_at'=>$now,
            ]);

        return response()->json([
            'acknowledged'=>true,
            'collector_id'=>$agent->collector_id,
            'received_at'=>$now->toIso8601String(),
        ]);
    }
}
