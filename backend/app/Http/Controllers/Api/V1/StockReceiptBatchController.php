<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StockReceiptBatchRequest;
use App\Http\Resources\StockReceiptBatchResource;
use App\Models\StockReceiptBatch;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use App\Services\CustomerPortfolio;
use App\Services\StockReceiptBatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class StockReceiptBatchController extends Controller
{
    public function __construct(
        private readonly StockReceiptBatchService $batches,
        private readonly CustomerPortfolio $portfolio,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->portfolio->assertFull($request);
        $filters = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'supplier_id' => ['sometimes', 'integer', 'min:1'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);
        $term = mb_strtolower(trim($filters['q'] ?? ''));
        return StockReceiptBatchResource::collection(
            StockReceiptBatch::query()
                ->where('tenant_id', $request->attributes->get('nexa_tenant')->id)
                ->when($term !== '', static fn ($query) => $query
                    ->whereRaw('LOWER(invoice_reference) LIKE ?', ['%'.$term.'%']))
                ->when(isset($filters['supplier_id']), static fn ($query) => $query
                    ->where('supplier_id', $filters['supplier_id']))
                ->with('supplier:id,code,name')
                ->orderByDesc('id')
                ->paginate($filters['per_page'] ?? 25)
        );
    }

    public function store(StockReceiptBatchRequest $request): JsonResponse
    {
        $this->portfolio->assertFull($request);
        [$batch, $created] = $this->batches->create(
            $request->attributes->get('nexa_tenant'), $request->user(), $request->validated()
        );
        return (new StockReceiptBatchResource($batch))
            ->response()->setStatusCode($created ? 201 : 200);
    }

    public function show(Request $request, int $batch): StockReceiptBatchResource
    {
        $this->portfolio->assertFull($request);
        return new StockReceiptBatchResource($this->batches->find(
            (int) $request->attributes->get('nexa_tenant')->id, $batch
        ));
    }
}
