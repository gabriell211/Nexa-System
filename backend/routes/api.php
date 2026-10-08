<?php
declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\CollectorIngestController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\PrinterController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('/collector/ingest', CollectorIngestController::class)->middleware('throttle:120,1');
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
    Route::middleware(['auth:sanctum', 'nexa.tenant'])->group(function (): void {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/dashboard', DashboardController::class);
        Route::apiResource('customers', CustomerController::class)->only(['index', 'store', 'show']);
        Route::apiResource('printers', PrinterController::class)->only(['index', 'store', 'show']);
    });
});
