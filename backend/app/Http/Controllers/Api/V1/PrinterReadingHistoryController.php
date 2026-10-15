<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\PrinterReadingHistoryRequest;
use App\Services\CustomerPortfolio;
use App\Services\PrinterReadingHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

final class PrinterReadingHistoryController extends Controller
{
    public function __construct(
        private readonly CustomerPortfolio $portfolio,
        private readonly PrinterReadingHistory $history,
    ) {}

    public function __invoke(PrinterReadingHistoryRequest $request,int $printer): JsonResponse
    {
        $tenant=$request->attributes->get('nexa_tenant');
        $device=$this->portfolio->printers($tenant->printers(),$request)
            ->findOrFail($printer);

        return response()->json($this->history->paginate($device,$request->validated()));
    }
}
