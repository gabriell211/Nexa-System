<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\CollectorIngestRequest;
use App\Services\CollectorAuthenticator;
use App\Services\CollectorReadingsIngestor;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

final class CollectorIngestController extends Controller
{
    public function __construct(
        private readonly CollectorAuthenticator $authenticator,
        private readonly CollectorReadingsIngestor $ingestor,
    ) {}

    public function __invoke(CollectorIngestRequest $request): JsonResponse
    {
        $agent = $this->authenticator->authenticate($request, $request->validated('collector_id'));
        return response()->json(['acknowledged' => $this->ingestor->ingest($agent, $request->validated('samples'))]);
    }
}
