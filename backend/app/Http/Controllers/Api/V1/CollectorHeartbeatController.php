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
        $values=['heartbeat_at'=>$now,'updated_at'=>$now];
        $watchdog=$request->validated('watchdog');
        if (is_array($watchdog)) {
            // Only server time is authoritative: this is last REPORTED state,
            // not proof the service is currently up or that SNMP is healthy.
            $values['watchdog_recent_incidents']=(int)$watchdog['recent_incidents'];
            $values['watchdog_circuit_open_reported']=(bool)$watchdog['circuit_open'];
            $values['watchdog_reported_at']=$now;
        }
        $updated=DB::table('collector_agents')
            ->where('id',$agent->id)->where('active',true)->update($values);

        if ($updated!==1) abort(401,'Collector has been revoked.');

        return response()->json([
            'acknowledged'=>true,
            'collector_id'=>$agent->collector_id,
            'received_at'=>$now->toIso8601String(),
        ]);
    }
}
