<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Services\CollectorAuthenticator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class CollectorIdentityController extends Controller
{
    public function __construct(private readonly CollectorAuthenticator $authenticator) {}

    public function __invoke(Request $request): JsonResponse
    {
        $fields = $request->validate(['collector_id' => ['required', 'uuid']]);
        $agent = $this->authenticator->authenticate($request, $fields['collector_id']);
        $customer = $agent->customer()->firstOrFail();

        return response()->json([
            'collector_id' => $agent->collector_id,
            'customer_id' => (int) $customer->id,
            'customer_name' => $customer->name,
        ], 200, [
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
