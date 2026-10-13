<?php
declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuditEntryController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BrowserAuthController;
use App\Http\Controllers\Api\V1\CollectorIngestController;
use App\Http\Controllers\Api\V1\CostCenterController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\CustomerDepartmentController;
use App\Http\Controllers\Api\V1\CustomerLocationController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\PrinterController;
use App\Http\Controllers\Api\V1\PrinterAssignmentController;
use App\Http\Controllers\Api\V1\StaffPortfolioController;
use App\Http\Controllers\Api\V1\StockItemController;
use App\Http\Controllers\Api\V1\StockLocationController;
use App\Http\Controllers\Api\V1\StockBalanceController;
use App\Http\Controllers\Api\V1\StockMovementController;
use App\Http\Controllers\Api\V1\StockOperatorController;
use Illuminate\Support\Facades\Route;

// Identical resources, separate authentication transports. Web requests receive
// session+CSRF middleware; machine bearer clients never receive session cookies.
$registerBackoffice = static function (): void {
    Route::middleware('nexa.roles:owner,admin,manager,supervisor,technician,finance,warehouse')->group(function (): void {
        Route::get('/dashboard', DashboardController::class);
        Route::apiResource('customers', CustomerController::class)->only(['index', 'show']);
        Route::apiResource('printers', PrinterController::class)->only(['index', 'show']);
        Route::get('/printers/{printer}/assignments', [PrinterAssignmentController::class, 'index']);
        Route::apiResource('customers.locations', CustomerLocationController::class)->only(['index', 'show']);
        Route::apiResource('customers.locations.departments', CustomerDepartmentController::class)->only(['index', 'show']);
        Route::apiResource('customers.cost-centers', CostCenterController::class)
            ->parameters(['cost-centers' => 'costCenter'])->only(['index', 'show']);
    });

    Route::middleware('nexa.roles:owner,admin,manager')->group(function (): void {
        Route::apiResource('customers', CustomerController::class)->only(['store', 'update', 'destroy']);
        Route::apiResource('printers', PrinterController::class)->only(['store', 'update', 'destroy']);
        Route::post('/printers/{printer}/assignments', [PrinterAssignmentController::class, 'store']);
        Route::post('/printers/{printer}/unassign', [PrinterAssignmentController::class, 'release']);
        Route::apiResource('customers.locations', CustomerLocationController::class)->only(['store', 'update', 'destroy']);
        Route::apiResource('customers.locations.departments', CustomerDepartmentController::class)->only(['store', 'update', 'destroy']);
        Route::apiResource('customers.cost-centers', CostCenterController::class)
            ->parameters(['cost-centers' => 'costCenter'])->only(['store', 'update', 'destroy']);
    });

    Route::middleware('nexa.roles:owner,admin,manager,warehouse')->group(static function (): void {
        Route::get('/stock/items', [StockItemController::class, 'index']);
        Route::get('/stock/operators', StockOperatorController::class);
        Route::get('/stock/locations', [StockLocationController::class, 'index']);
        Route::get('/stock/balances', StockBalanceController::class);
        Route::get('/stock/movements', [StockMovementController::class, 'index']);
        Route::post('/stock/items', [StockItemController::class, 'store']);
        Route::post('/stock/locations', [StockLocationController::class, 'store']);
        Route::post('/stock/movements', [StockMovementController::class, 'store']);
    });

    Route::get('/audit-entries', AuditEntryController::class)->middleware('nexa.roles:owner,admin');
    Route::middleware('nexa.roles:owner,admin')->group(static function (): void {
        Route::get('/staff', [StaffPortfolioController::class, 'index']);
        Route::get('/staff/{user}/portfolio', [StaffPortfolioController::class, 'show']);
        Route::put('/staff/{user}/portfolio', [StaffPortfolioController::class, 'update']);
    });
};

Route::prefix('v1')->group(function () use ($registerBackoffice): void {
    Route::post('/collector/ingest', CollectorIngestController::class)->middleware('throttle:120,1');

    // Transitional non-browser API. Issuing new bearer credentials is disabled
    // by default; existing explicitly issued tokens remain scoped to a tenant.
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
    Route::name('machine.')->middleware(['nexa.bearer', 'auth:sanctum', 'nexa.tenant', 'nexa.portfolio'])->group(
        static function () use ($registerBackoffice): void {
            Route::get('/auth/me', [AuthController::class, 'me']);
            Route::post('/auth/logout', [AuthController::class, 'logout']);
            $registerBackoffice();
        }
    );

    Route::prefix('browser')->name('browser.')->middleware('web')->group(
        static function () use ($registerBackoffice): void {
            Route::get('/auth/csrf', [BrowserAuthController::class, 'csrf'])->middleware('throttle:120,1');
            Route::post('/auth/login', [BrowserAuthController::class, 'login'])->middleware('throttle:5,1');

            Route::middleware(['auth:web', 'nexa.browser-tenant', 'nexa.portfolio'])->group(
                static function () use ($registerBackoffice): void {
                    Route::get('/auth/me', [AuthController::class, 'me']);
                    Route::post('/auth/logout', [BrowserAuthController::class, 'logout']);
                    $registerBackoffice();
                }
            );
        }
    );
});
