<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

final class RequireMobileTechnician
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();
        if ($request->attributes->get('nexa_role') !== 'technician'
            || !$token instanceof PersonalAccessToken
            || !in_array('mobile:technician', $token->abilities, true)) {
            abort(403,'Aplicativo disponível somente para técnicos autorizados.');
        }
        return $next($request);
    }
}
