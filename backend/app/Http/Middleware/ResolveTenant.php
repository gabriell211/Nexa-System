<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (!$user instanceof User || !$user->active || $user->currentAccessToken() === null) {
            abort(401);
        }

        $abilities = $user->currentAccessToken()->abilities;
        $tenantAbilities = array_values(array_filter(
            $abilities,
            static fn (string $ability): bool => str_starts_with($ability, 'tenant:')
        ));

        if (count($tenantAbilities) !== 1 || !in_array('api', $abilities, true)) {
            abort(403, 'Invalid tenant-scoped token.');
        }

        $tenantId = substr($tenantAbilities[0], strlen('tenant:'));
        if (!ctype_digit($tenantId)) {
            abort(403);
        }
        $tenant = Tenant::query()->whereKey((int) $tenantId)->where('active', true)->first();
        $membership = $tenant ? $user->tenants()->whereKey($tenant->id)->first() : null;

        if ($membership === null) {
            abort(403, 'No active membership.');
        }

        $request->attributes->set('nexa_tenant', $tenant);
        $request->attributes->set('nexa_role', $membership->pivot->role);
        return $next($request);
    }
}
