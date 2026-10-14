<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\CollectorAgent;
use App\Models\Customer;
use App\Models\Tenant;
use Illuminate\Http\Request;

final class CollectorAuthenticator
{
    public function authenticate(Request $request, string $collectorId): CollectorAgent
    {
        $token=$request->bearerToken();
        if (!is_string($token) || strlen($token)!==64 || !ctype_xdigit($token)) {
            abort(401,'Collector authentication required.');
        }

        $agent=CollectorAgent::query()
            ->where('token_hash',hash('sha256',$token))
            ->where('active',true)
            ->first();
        if ($agent===null || $agent->collector_id!==$collectorId) {
            abort(401,'Invalid collector credentials.');
        }

        if (!Tenant::query()->whereKey($agent->tenant_id)->where('active',true)->exists() ||
            !Customer::query()->where('tenant_id',$agent->tenant_id)
                ->whereKey($agent->customer_id)->where('active',true)->exists()) {
            abort(403,'Collector organization or customer is inactive.');
        }

        return $agent;
    }
}
