<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

/** Exact customer-authorized fleet metrics, not totals of a paginated sample. */
final class ClientFleetSummaryController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        if ($request->attributes->get('nexa_role') !== 'customer'
            || $request->attributes->get('nexa_customer_scope') !== 'assigned') {
            abort(403, 'Acesso do cliente não vinculado ao parque.');
        }

        $tenant = $request->attributes->get('nexa_tenant');
        $allowed = DB::table('customer_user_access')
            ->select('customer_id')
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $request->user()->id);

        $printers = $tenant->printers()
            ->whereIn('printers.customer_id', $allowed);

        $readings = DB::table('printer_readings')
            ->join('printers', 'printer_readings.printer_id', '=', 'printers.id')
            ->where('printer_readings.tenant_id', $tenant->id)
            ->where('printers.tenant_id', $tenant->id)
            ->whereIn('printers.customer_id', $allowed)
            ->distinct()
            ->count('printers.id');

        $customers = DB::table('customer_user_access')
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $request->user()->id)
            ->count();

        return response()->json([
            'printers' => (clone $printers)->count(),
            'active' => (clone $printers)->where('active', true)->count(),
            'printers_with_readings' => $readings,
            'assigned_customers' => $customers,
        ])->header('Cache-Control', 'no-store, private');
    }
}
