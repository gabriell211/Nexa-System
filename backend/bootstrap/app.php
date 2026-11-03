<?php
declare(strict_types=1);

use App\Http\Middleware\RequireTenantRole;
use App\Http\Middleware\RequireMobileTechnician;
use App\Http\Middleware\RequireMachineBearer;
use App\Http\Middleware\EnsureCustomerPortfolio;
use App\Http\Middleware\ResolveBrowserTenant;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // API sessions are not HTML pages: unauthenticated API calls must fail with 401.
        $middleware->redirectGuestsTo(static fn (Request $request): ?string =>
            $request->is('api/*') ? null : '/login'
        );
        $middleware->alias([
            'nexa.tenant' => ResolveTenant::class,
            'nexa.browser-tenant' => ResolveBrowserTenant::class,
            'nexa.bearer' => RequireMachineBearer::class,
            'nexa.portfolio' => EnsureCustomerPortfolio::class,
            'nexa.roles' => RequireTenantRole::class,
            'nexa.mobile-technician' => RequireMobileTechnician::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // API callers must never be redirected to a non-existent login page.
        $exceptions->shouldRenderJsonWhen(
            static fn (Request $request, \Throwable $exception): bool =>
                $request->is('api/*') || $request->expectsJson()
        );
    })->create();
