<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\AuditEntry;
use App\Services\CustomerPortfolio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class PrinterActionHistoryController extends Controller
{
    public function __construct(private readonly CustomerPortfolio $portfolio) {}

    public function __invoke(Request $request, int $printer): JsonResponse
    {
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,50'],
        ]);
        $tenant = $request->attributes->get('nexa_tenant');
        $machine = $this->portfolio->printers($tenant->printers(), $request)
            ->findOrFail($printer);
        // Do not return before/after snapshots: historical records may hold
        // values the current role is no longer allowed to access.
        $page = AuditEntry::query()
            ->where('tenant_id', $tenant->id)
            ->where('entity_type', 'Printer')
            ->where('entity_id', $machine->id)
            ->with('actor:id,name')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 20);
        return response()->json([
            'data' => $page->getCollection()->map(static fn (AuditEntry $entry): array => [
                'id' => $entry->id,
                'action' => $entry->action,
                'actor' => $entry->actor?->name,
                'created_at' => $entry->created_at?->toIso8601String(),
            ]),
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'total' => $page->total(),
        ])->header('Cache-Control', 'no-store, private');
    }
}
