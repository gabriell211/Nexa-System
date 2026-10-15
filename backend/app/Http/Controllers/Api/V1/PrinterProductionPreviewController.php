<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\PrinterProductionPreviewRequest;
use App\Services\CustomerPortfolio;
use App\Services\PrinterProductionPreview;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

final class PrinterProductionPreviewController extends Controller
{
    public function __construct(
        private readonly CustomerPortfolio $portfolio,
        private readonly PrinterProductionPreview $preview,
    ) {}

    public function __invoke(PrinterProductionPreviewRequest $request, int $printer): JsonResponse
    {
        $tenant = $request->attributes->get('nexa_tenant');
        $device = $this->portfolio->printers($tenant->printers(), $request)
            ->findOrFail($printer);
        $validated = $request->validated();

        return response()->json($this->preview->calculate(
            $device,
            $validated['start_date'],
            $validated['end_date'],
        ));
    }
}
