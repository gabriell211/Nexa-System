<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\ClientPrinterResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

/**
 * The customer portal uses a separate, read-only endpoint.
 * Never use an all-tenant printer query or a request-supplied customer ID.
 */
final class ClientPrinterController extends Controller
{
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        // Customer users must be explicitly assigned. A legacy 'all'
        // pivot value must NEVER grant a customer access to an entire MSP.
        if ($request->attributes->get('nexa_role') !== 'customer'
            || $request->attributes->get('nexa_customer_scope') !== 'assigned') {
            abort(403, 'Acesso do cliente ainda não foi vinculado ao seu parque.');
        }

        $tenant = $request->attributes->get('nexa_tenant');
        $filters = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'active_only' => ['sometimes', 'in:0,1,true,false'],
        ]);
        $term = mb_strtolower(trim($filters['q'] ?? ''));

        // Subquery is evaluated at request time, so revocation applies
        // immediately. Both tenant and authenticated user are enforced.
        $allowed = DB::table('customer_user_access')
            ->select('customer_id')
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $request->user()->id);

        $devices = $tenant->printers()
            ->whereIn('printers.customer_id', $allowed)
            ->with([
                'customer:id,name',
                'currentAssignment.location:id,name',
                'currentAssignment.department:id,name',
                'currentAssignment.costCenter:id,name,code',
                'latestReading',
            ])
            ->when($request->boolean('active_only'), fn (Builder $query) => $query->where('active', true))
            ->when($term !== '', function (Builder $query) use ($term): void {
                $query->where(static function (Builder $match) use ($term): void {
                    $like = '%'.$term.'%';
                    $match->whereRaw('LOWER(manufacturer) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(model) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(serial_number) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(ip_address) LIKE ?', [$like]);
                });
            })
            ->orderBy('printers.id', 'desc')
            ->paginate($filters['per_page'] ?? 25);

        return ClientPrinterResource::collection($devices)
            ->additional(['scope' => 'assigned_only']);
    }
}
