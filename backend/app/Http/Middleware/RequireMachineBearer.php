<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireMachineBearer
{
    public function handle(Request $request, Closure $next): Response
    {
        // A browser session must NEVER implicitly authorize a machine-token route.
        if (!$request->bearerToken()) {
            abort(401, 'Bearer authentication required.');
        }
        return $next($request);
    }
}
