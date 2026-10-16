<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Services\CustomerCollectorCredentialService;
use App\Services\CustomerPortfolio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class CustomerCollectorCredentialController extends Controller
{
    public function __construct(
        private readonly CustomerCollectorCredentialService $credentials,
        private readonly CustomerPortfolio $portfolio,
    ) {}

    public function __invoke(Request $request, int $customer): JsonResponse
    {
        $this->portfolio->assertFull($request);

        $issued = $this->credentials->issue(
            $request->attributes->get('nexa_tenant'),
            $request->user(),
            $customer,
        );

        // A credential is never made available by GET or a customer resource.
        return response()->json($issued, 201, [
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
