<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireTenantRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $role = $request->attributes->get('nexa_role');

        if (!is_string($role) || !in_array($role, $roles, true)) {
            abort(403, 'Insufficient permission for this tenant.');
        }

        return $next($request);
    }
}
