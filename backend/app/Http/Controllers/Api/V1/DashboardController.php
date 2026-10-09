<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Routing\Controller;

final class DashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('nexa_tenant');
        return response()->json([
            'customers' => $tenant->customers()->count(),
            'printers' => $tenant->printers()->count(),
            'printers_with_readings' => $tenant->printers()->whereHas('readings')->count(),
            'locations' => DB::table('customer_locations')->where('tenant_id', $tenant->id)->count(),
            'departments' => DB::table('customer_departments')->where('tenant_id', $tenant->id)->count(),
            'cost_centers' => DB::table('cost_centers')->where('tenant_id', $tenant->id)->count(),
            'note' => 'Indicadores de telemetria permanecem indisponíveis até implantação do coletor.',
        ]);
    }
}
