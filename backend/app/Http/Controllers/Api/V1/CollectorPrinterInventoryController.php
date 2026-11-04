<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\CollectorPrinterInventoryRequest;
use App\Services\CollectorAuthenticator;
use App\Services\CollectorPrinterInventoryIngestor;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

final class CollectorPrinterInventoryController extends Controller
{
    public function __construct(
        private readonly CollectorAuthenticator $authenticator,
        private readonly CollectorPrinterInventoryIngestor $ingestor,
    ) {}

    public function __invoke(CollectorPrinterInventoryRequest $request): JsonResponse
    {
        $data = $request->validated();
        $agent = $this->authenticator->authenticate($request, $data['collector_id']);
        return response()->json([
            'collector_id' => $agent->collector_id,
            'acknowledged' => $this->ingestor->ingest($agent, $data['printers']),
        ]);
    }
}
