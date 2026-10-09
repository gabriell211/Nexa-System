<?php
declare(strict_types=1);

use App\Http\Middleware\RequireTenantRole;
use App\Http\Middleware\ResolveBrowserTenant;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'nexa.tenant' => ResolveTenant::class,
            'nexa.browser-tenant' => ResolveBrowserTenant::class,
            'nexa.roles' => RequireTenantRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
