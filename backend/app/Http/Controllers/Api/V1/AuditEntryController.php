<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\AuditEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class AuditEntryController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('nexa_tenant');
        $page = AuditEntry::query()
            ->where('tenant_id', $tenant->id)
            ->with('actor:id,name')
            ->orderByDesc('id')
            ->paginate(25);

        return response()->json($page->through(static fn (AuditEntry $entry): array => [
            'id' => $entry->id,
            'actor' => $entry->actor?->only(['id', 'name']),
            'action' => $entry->action,
            'entity_type' => $entry->entity_type,
            'entity_id' => $entry->entity_id,
            'origin' => $entry->origin,
            'before_state' => $entry->before_state,
            'after_state' => $entry->after_state,
            'created_at' => $entry->created_at,
        ]));
    }
}
