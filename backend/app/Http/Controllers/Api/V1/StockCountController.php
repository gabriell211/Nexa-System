<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StockCountRequest;
use App\Http\Requests\StockCountReviewRequest;
use App\Http\Resources\StockCountResource;
use App\Models\StockCount;
use App\Services\CustomerPortfolio;
use App\Services\StockCountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

final class StockCountController extends Controller
{
    public function __construct(
        private readonly StockCountService $counts,
        private readonly CustomerPortfolio $portfolio,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->portfolio->assertFull($request);
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'status' => ['sometimes', Rule::in(['pending', 'approved', 'rejected'])],
            'item_id' => ['sometimes', 'integer', 'min:1'],
            'location_id' => ['sometimes', 'integer', 'min:1'],
        ]);
        return StockCountResource::collection(
            StockCount::query()->where('tenant_id', $request->attributes->get('nexa_tenant')->id)
                ->when(isset($filters['status']), fn ($query) => $query->where('status', $filters['status']))
                ->when(isset($filters['item_id']), fn ($query) => $query->where('item_id', $filters['item_id']))
                ->when(isset($filters['location_id']), fn ($query) => $query->where('location_id', $filters['location_id']))
                ->with(['item', 'location', 'countedBy:id,name', 'reviewedBy:id,name'])
                ->orderByDesc('id')->paginate($filters['per_page'] ?? 25)
        );
    }

    public function show(Request $request, int $count): StockCountResource
    {
        $this->portfolio->assertFull($request);
        return new StockCountResource(StockCount::query()
            ->where('tenant_id', $request->attributes->get('nexa_tenant')->id)
            ->with(['item', 'location', 'countedBy:id,name', 'reviewedBy:id,name'])
            ->findOrFail($count));
    }

    public function store(StockCountRequest $request): JsonResponse
    {
        $this->portfolio->assertFull($request);
        $count = $this->counts->record(
            $request->attributes->get('nexa_tenant'), $request->user(), $request->validated()
        );
        return (new StockCountResource($count))->response()->setStatusCode(201);
    }

    public function approve(StockCountReviewRequest $request, int $count): StockCountResource
    {
        $this->portfolio->assertFull($request);
        return new StockCountResource($this->counts->review(
            $request->attributes->get('nexa_tenant'), $request->user(), $count,
            'approved', $request->validated('reason')
        ));
    }

    public function reject(StockCountReviewRequest $request, int $count): StockCountResource
    {
        $this->portfolio->assertFull($request);
        return new StockCountResource($this->counts->review(
            $request->attributes->get('nexa_tenant'), $request->user(), $count,
            'rejected', $request->validated('reason')
        ));
    }
}
