<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\CustomerPortfolio;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureCustomerPortfolio
{
    public function __construct(private readonly CustomerPortfolio $portfolio) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->portfolio->hasFullAccess($request)) {
            return $next($request);
        }

        $tenant = $request->attributes->get('nexa_tenant');
        $customer = $request->route('customer');
        if ($customer !== null) {
            if (!ctype_digit((string) $customer)) abort(404);
            $this->portfolio->assertCustomer($request, (int) $customer);
        }

        $printer = $request->route('printer');
        if ($printer !== null) {
            if (!ctype_digit((string) $printer)) abort(404);
            $device = $tenant->printers()->findOrFail((int) $printer);
            $this->portfolio->assertCustomer($request, (int) $device->customer_id);
        }

        // Validate scope for creating a printer before the FormRequest executes.
        // Invalid IDs remain the responsibility of PrinterRequest.
        if ($request->isMethod('POST') &&
            $request->is('api/v1/printers', 'api/v1/browser/printers')) {
            $provided = $request->input('customer_id');
            if (is_int($provided) || (is_string($provided) && ctype_digit($provided))) {
                $this->portfolio->assertCustomer($request, (int) $provided);
            }
        }

        return $next($request);
    }
}
