<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\PrinterRequest;
use Illuminate\Database\Eloquent\Builder;
use App\Http\Resources\PrinterResource;
use App\Services\PrinterService;
use App\Services\CustomerPortfolio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

final class PrinterController extends Controller
{
    public function __construct(private readonly PrinterService $printers,
        private readonly CustomerPortfolio $portfolio) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $tenant = $request->attributes->get('nexa_tenant');
        $filters = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'active_only' => ['sometimes', 'in:0,1,true,false'],
            'customer_id' => ['sometimes', 'integer', 'min:1'],
        ]);
        $term = mb_strtolower(trim($filters['q'] ?? ''));

        return PrinterResource::collection(
            $this->portfolio->printers($tenant->printers(), $request)
                ->with(['customer:id,name','currentAssignment.location:id,name','latestReading'])
                ->when(isset($filters['customer_id']), fn (Builder $query) => $query->where('customer_id', (int) $filters['customer_id']))
                ->when($request->boolean('active_only'), fn (Builder $query) => $query->where('active', true))
                ->when($term !== '', function (Builder $query) use ($term): void {
                    $query->where(function (Builder $match) use ($term): void {
                        $like = '%'.$term.'%';
                        $match->whereRaw('LOWER(manufacturer) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(model) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(serial_number) LIKE ?', [$like]);
                    });
                })
                ->orderByDesc('id')
                ->paginate($filters['per_page'] ?? 25)
        );
    }

    public function store(PrinterRequest $request): JsonResponse
    {
        $printer = $this->printers->create(
            $request->attributes->get('nexa_tenant'),
            $request->user(),
            $request->validated()
        );

        return (new PrinterResource($printer->load(['customer:id,name','currentAssignment.location:id,name'])))->response()->setStatusCode(201);
    }

    public function show(Request $request, int $printer): PrinterResource
    {
        $item = $request->attributes->get('nexa_tenant')->printers()
            ->with(['customer:id,name', 'currentAssignment.location:id,name', 'latestReading', 'readings' => fn ($query) => $query->orderByDesc('collected_at')->orderByDesc('id')->limit(20)])
            ->findOrFail($printer);

        return new PrinterResource($item);
    }

    public function update(PrinterRequest $request, int $printer): PrinterResource
    {
        $item = $this->printers->update(
            $request->attributes->get('nexa_tenant'),
            $request->user(),
            $printer,
            $request->validated()
        );

        return new PrinterResource($item->load('customer:id,name'));
    }

    public function destroy(Request $request, int $printer): JsonResponse
    {
        $this->printers->deactivate($request->attributes->get('nexa_tenant'), $request->user(), $printer);

        return response()->json(null, 204);
    }
}
