<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ResolveBrowserTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('web');
        $id = $request->session()->get('nexa_tenant_id');

        if (!$user instanceof User || !$user->active ||
            !is_int($id) || $id < 1) {
            abort(401);
        }

        $tenant = Tenant::query()->whereKey($id)->where('active', true)->first();
        $membership = $tenant ? $user->tenants()->whereKey($tenant->id)->first() : null;
        if ($membership === null) {
            abort(403, 'No active membership for this company.');
        }

        // Resolve dynamically: removing a membership takes effect on the next request.
        $request->attributes->set('nexa_tenant', $tenant);
        $request->attributes->set('nexa_role', $membership->pivot->role);

        return $next($request);
    }
}
