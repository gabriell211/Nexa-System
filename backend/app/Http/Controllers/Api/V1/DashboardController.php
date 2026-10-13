<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\CustomerPortfolio;
use Illuminate\Routing\Controller;

final class DashboardController extends Controller
{
    public function __construct(private readonly CustomerPortfolio $portfolio) {}

    public function __invoke(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('nexa_tenant');
        $full = $this->portfolio->hasFullAccess($request);
        $allowed = $this->portfolio->assignedIds($request);
        $customerIds = DB::table('customers')->select('id')
            ->where('tenant_id', $tenant->id);
        if (!$full) {
            $customerIds->whereIn('id', $allowed);
        }
        $printerQuery = $this->portfolio->printers($tenant->printers(), $request);

        return response()->json([
            'customers' => $this->portfolio->customers($tenant->customers(), $request)->count(),
            'printers' => (clone $printerQuery)->count(),
            'printers_with_readings' => (clone $printerQuery)->whereHas('readings')->count(),
            'locations' => DB::table('customer_locations')->where('tenant_id', $tenant->id)
                ->whereIn('customer_id', clone $customerIds)->count(),
            'departments' => DB::table('customer_departments')->where('tenant_id', $tenant->id)
                ->whereIn('customer_id', clone $customerIds)->count(),
            'cost_centers' => DB::table('cost_centers')->where('tenant_id', $tenant->id)
                ->whereIn('customer_id', clone $customerIds)->count(),
            'note' => 'Indicadores de telemetria permanecem indisponíveis até implantação do coletor.',
        ]);
    }
}
