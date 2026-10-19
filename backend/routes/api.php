<?php
declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuditEntryController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BrowserAuthController;
use App\Http\Controllers\Api\V1\CollectorIngestController;
use App\Http\Controllers\Api\V1\CollectorHeartbeatController;
use App\Http\Controllers\Api\V1\CollectorAgentController;
use App\Http\Controllers\Api\V1\CollectorHealthIncidentController;
use App\Http\Controllers\Api\V1\ResendWebhookController;
use App\Http\Controllers\Api\V1\CostCenterController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\CustomerCollectorCredentialController;
use App\Http\Controllers\Api\V1\CustomerContractController;
use App\Http\Controllers\Api\V1\CustomerDepartmentController;
use App\Http\Controllers\Api\V1\CustomerLocationController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\PrinterController;
use App\Http\Controllers\Api\V1\PrinterActionHistoryController;
use App\Http\Controllers\Api\V1\ClientPrinterController;
use App\Http\Controllers\Api\V1\ClientFleetSummaryController;
use App\Http\Controllers\Api\V1\ClientFleetAnalyticsController;
use App\Http\Controllers\Api\V1\PrinterReadingHistoryController;
use App\Http\Controllers\Api\V1\PrinterProductionPreviewController;
use App\Http\Controllers\Api\V1\PrinterAssignmentController;
use App\Http\Controllers\Api\V1\StaffPortfolioController;
use App\Http\Controllers\Api\V1\AccountProvisioningController;
use App\Http\Controllers\Api\V1\AccountActivationController;
use App\Http\Controllers\Api\V1\StockItemController;
use App\Http\Controllers\Api\V1\StockLocationController;
use App\Http\Controllers\Api\V1\StockBalanceController;
use App\Http\Controllers\Api\V1\StockMovementController;
use App\Http\Controllers\Api\V1\StockOperatorController;
use App\Http\Controllers\Api\V1\StockShipmentController;
use App\Http\Controllers\Api\V1\StockLotController;
use App\Http\Controllers\Api\V1\StockSupplierController;
use App\Http\Controllers\Api\V1\StockCompatibilityController;
use App\Http\Controllers\Api\V1\StockDocumentedReceiptController;
use App\Http\Controllers\Api\V1\StockReceiptBatchController;
use App\Http\Controllers\Api\V1\StockCountController;
use App\Http\Controllers\Api\V1\StockReorderPolicyController;
use App\Http\Controllers\Api\V1\StockReplenishmentPlanController;
use App\Http\Controllers\Api\V1\StockPlanShipmentController;
use App\Http\Controllers\Api\V1\StockPurchaseOrderController;
use App\Http\Controllers\Api\V1\StockPurchaseReceiptMatchController;
use App\Http\Controllers\Api\V1\StockSupplierReturnController;
use App\Http\Controllers\Api\V1\StockSupplierReplacementController;
use App\Http\Controllers\Api\V1\ServiceTicketController;
use App\Http\Controllers\Api\V1\ClientTicketController;
use App\Http\Controllers\Api\V1\PrinterAlertController;
use App\Http\Controllers\Api\V1\ServiceTicketOperatorController;
use App\Http\Controllers\Api\V1\MobileTechnicianAuthController;
use App\Http\Controllers\Api\V1\MobileTechnicianController;
use App\Http\Controllers\Api\V1\TicketPartRequestReviewController;
use App\Http\Controllers\Api\V1\TicketSignatureController;
use Illuminate\Support\Facades\Route;

// Identical resources, separate authentication transports. Web requests receive
// session+CSRF middleware; machine bearer clients never receive session cookies.
$registerBackoffice = static function (): void {
    Route::middleware('nexa.roles:owner,admin,manager,supervisor,technician,finance,warehouse')->group(function (): void {
        Route::get('/dashboard', DashboardController::class);
        Route::apiResource('customers', CustomerController::class)->only(['index', 'show']);
        Route::apiResource('contracts', CustomerContractController::class)->only(['index', 'show']);
        Route::get('/contracts/{contract}/cycle', [CustomerContractController::class, 'cycle']);
        Route::apiResource('printers', PrinterController::class)->only(['index', 'show']);
        Route::get('/printers/{printer}/readings', PrinterReadingHistoryController::class);
        Route::get('/printers/{printer}/actions', PrinterActionHistoryController::class);
        Route::get('/printers/{printer}/production-preview', PrinterProductionPreviewController::class);
        Route::get('/printers/{printer}/assignments', [PrinterAssignmentController::class, 'index']);
        Route::apiResource('customers.locations', CustomerLocationController::class)->only(['index', 'show']);
        Route::apiResource('customers.locations.departments', CustomerDepartmentController::class)->only(['index', 'show']);
        Route::apiResource('customers.cost-centers', CostCenterController::class)
            ->parameters(['cost-centers' => 'costCenter'])->only(['index', 'show']);
    });

    Route::middleware('nexa.roles:owner,admin,manager')->group(function (): void {
        Route::apiResource('customers', CustomerController::class)->only(['store', 'update', 'destroy']);
        Route::apiResource('contracts', CustomerContractController::class)->only(['store', 'update']);
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
        Route::get('/stock/suppliers', [StockSupplierController::class, 'index']);
        Route::get('/stock/receipts', [StockDocumentedReceiptController::class, 'index']);
        Route::get('/stock/receipt-batches', [StockReceiptBatchController::class, 'index']);
        Route::get('/stock/receipt-batches/{batch}', [StockReceiptBatchController::class, 'show']);
        Route::get('/stock/items/{item}/compatibilities', [StockCompatibilityController::class, 'index']);
        Route::get('/stock/items/{item}/compatibility-check', [StockCompatibilityController::class, 'check']);
        Route::get('/stock/operators', StockOperatorController::class);
        Route::get('/stock/locations', [StockLocationController::class, 'index']);
        Route::get('/stock/balances', StockBalanceController::class);
        Route::get('/stock/reorder-needs', [StockReorderPolicyController::class, 'index']);
        Route::get('/stock/replenishment-plans', [StockReplenishmentPlanController::class, 'index']);
        Route::get('/stock/purchase-orders', [StockPurchaseOrderController::class, 'index']);
        Route::get('/stock/purchase-orders/{order}', [StockPurchaseOrderController::class, 'show']);
        Route::get('/stock/supplier-returns', [StockSupplierReturnController::class, 'index']);
        Route::get('/stock/supplier-replacements', [StockSupplierReplacementController::class, 'index']);
        Route::get('/stock/supplier-replacements/{case}', [StockSupplierReplacementController::class, 'show']);
        Route::get('/stock/supplier-returns/{return}', [StockSupplierReturnController::class, 'show']);
        Route::get('/stock/replenishment-plans/{plan}', [StockReplenishmentPlanController::class, 'show']);
        Route::get('/stock/lots', StockLotController::class);
        Route::get('/stock/movements', [StockMovementController::class, 'index']);
        Route::get('/stock/shipments', [StockShipmentController::class, 'index']);
        Route::get('/stock/shipments/{shipment}', [StockShipmentController::class, 'show']);
        Route::get('/stock/counts', [StockCountController::class, 'index']);
        Route::get('/stock/counts/{count}', [StockCountController::class, 'show']);
        Route::post('/stock/items', [StockItemController::class, 'store']);
        Route::post('/stock/suppliers', [StockSupplierController::class, 'store']);
        Route::patch('/stock/suppliers/{supplier}', [StockSupplierController::class, 'update']);
        Route::post('/stock/receipts', [StockDocumentedReceiptController::class, 'store']);
        Route::post('/stock/receipt-batches', [StockReceiptBatchController::class, 'store']);
        Route::post('/stock/items/{item}/compatibilities', [StockCompatibilityController::class, 'store']);
        Route::delete('/stock/items/{item}/compatibilities/{compatibility}', [StockCompatibilityController::class, 'destroy']);
        Route::post('/stock/locations', [StockLocationController::class, 'store']);
        Route::post('/stock/movements', [StockMovementController::class, 'store']);
        Route::post('/stock/shipments', [StockShipmentController::class, 'store']);
        Route::post('/stock/shipments/{shipment}/dispatch', [StockShipmentController::class, 'dispatch']);
        Route::post('/stock/shipments/{shipment}/receive', [StockShipmentController::class, 'receive']);
        Route::post('/stock/shipments/{shipment}/close', [StockShipmentController::class, 'close']);
        Route::post('/stock/counts', [StockCountController::class, 'store']);
        Route::put('/stock/reorder-policies/{item}/{location}', [StockReorderPolicyController::class, 'upsert']);
        Route::delete('/stock/reorder-policies/{item}/{location}', [StockReorderPolicyController::class, 'destroy']);
        Route::post('/stock/replenishment-plans', [StockReplenishmentPlanController::class, 'store']);
        Route::post('/stock/purchase-orders', [StockPurchaseOrderController::class, 'store']);
        Route::post('/stock/supplier-returns', [StockSupplierReturnController::class, 'store']);
        Route::post('/stock/supplier-replacements', [StockSupplierReplacementController::class, 'store']);
        Route::post('/stock/supplier-replacements/{case}/events', [StockSupplierReplacementController::class, 'event']);
        Route::post('/stock/supplier-replacements/{case}/receipts', [StockSupplierReplacementController::class, 'receipt']);
        Route::post('/stock/supplier-returns/{return}/cancel', [StockSupplierReturnController::class, 'cancel']);
        Route::post('/stock/purchase-orders/{order}/reconcile-receipt', [StockPurchaseReceiptMatchController::class, 'store']);
        Route::post('/stock/purchase-orders/{order}/cancel', [StockPurchaseOrderController::class, 'cancel']);
        Route::post('/stock/replenishment-plans/{plan}/items/{line}/reserve-shipment', [StockPlanShipmentController::class, 'store']);
        Route::patch('/stock/replenishment-plans/{plan}', [StockReplenishmentPlanController::class, 'update']);
    });

    Route::middleware('nexa.roles:owner,admin,manager')->group(static function (): void {
        Route::post('/stock/counts/{count}/approve', [StockCountController::class, 'approve']);
        Route::post('/stock/counts/{count}/reject', [StockCountController::class, 'reject']);
        Route::post('/stock/replenishment-plans/{plan}/approve', [StockReplenishmentPlanController::class, 'approve']);
        Route::post('/stock/purchase-orders/{order}/approve', [StockPurchaseOrderController::class, 'approve']);
        Route::post('/stock/supplier-returns/{return}/approve', [StockSupplierReturnController::class, 'approve']);
        Route::post('/stock/replenishment-plans/{plan}/cancel', [StockReplenishmentPlanController::class, 'cancel']);
    });

    Route::middleware('nexa.roles:owner,admin,manager,supervisor,warehouse')->group(static function (): void {
        Route::get('/tickets/part-requests', [TicketPartRequestReviewController::class, 'index']);
        Route::post('/tickets/part-requests/{part}/review', [TicketPartRequestReviewController::class, 'review']);
        Route::get('/tickets/{ticket}/signature/image', [TicketSignatureController::class, 'download']);
    });

    Route::post('/tickets/{ticket}/assign', [ServiceTicketController::class, 'assign'])
        ->middleware('nexa.roles:owner,admin,manager,supervisor');

    Route::middleware('nexa.roles:owner,admin,manager,supervisor,technician')->group(static function (): void {
        Route::get('/collector-agents', CollectorAgentController::class);
        Route::get('/collector-health-incidents', CollectorHealthIncidentController::class);
        Route::get('/alerts', [PrinterAlertController::class, 'index']);
        Route::get('/alerts/{alert}', [PrinterAlertController::class, 'show']);
        Route::post('/alerts', [PrinterAlertController::class, 'store']);
        Route::post('/alerts/{alert}/transition', [PrinterAlertController::class, 'transition']);
        Route::post('/alerts/{alert}/link-ticket', [PrinterAlertController::class, 'linkTicket']);
        Route::get('/tickets', [ServiceTicketController::class, 'index']);
        Route::get('/tickets/operators', ServiceTicketOperatorController::class);
        Route::get('/tickets/{ticket}', [ServiceTicketController::class, 'show']);
        Route::post('/tickets', [ServiceTicketController::class, 'store']);
        Route::post('/tickets/{ticket}/transition', [ServiceTicketController::class, 'transition']);
        Route::post('/tickets/{ticket}/comments', [ServiceTicketController::class, 'comment']);
        Route::get('/tickets/{ticket}/attachments/{attachment}', [ClientTicketController::class, 'download']);
    });

    Route::get('/audit-entries', AuditEntryController::class)->middleware('nexa.roles:owner,admin');
    Route::middleware('nexa.roles:owner,admin')->group(static function (): void {
        Route::post('/customers/{customer}/collector-credentials', CustomerCollectorCredentialController::class)
            ->middleware('throttle:5,1');
        Route::get('/staff', [StaffPortfolioController::class, 'index']);
        Route::post('/accounts', [AccountProvisioningController::class, 'store'])->middleware('throttle:10,1,account-invites:');
        Route::get('/staff/{user}/portfolio', [StaffPortfolioController::class, 'show']);
        Route::put('/staff/{user}/portfolio', [StaffPortfolioController::class, 'update']);
    });
};

Route::prefix('v1')->group(function () use ($registerBackoffice): void {
    Route::post('/webhooks/resend', ResendWebhookController::class)->middleware('throttle:60,1');
    Route::post('/collector/ingest', CollectorIngestController::class)->middleware(['nexa.bearer','throttle:120,1']);
    Route::post('/collector/heartbeat', CollectorHeartbeatController::class)->middleware(['nexa.bearer','throttle:120,1']);

    // Transitional non-browser API. Issuing new bearer credentials is disabled
    // by default; existing explicitly issued tokens remain scoped to a tenant.
    Route::post('/mobile/auth/login', [MobileTechnicianAuthController::class, 'login'])
        ->middleware('throttle:5,1,mobile-login:');
    Route::prefix('mobile')->middleware([
        'nexa.bearer', 'auth:sanctum', 'nexa.tenant', 'nexa.mobile-technician',
    ])->group(static function (): void {
        Route::get('/auth/me', [MobileTechnicianAuthController::class, 'me']);
        Route::post('/auth/logout', [MobileTechnicianAuthController::class, 'logout']);
        Route::get('/tickets', [MobileTechnicianController::class, 'tickets']);
        Route::get('/tickets/{ticket}', [MobileTechnicianController::class, 'show']);
        Route::post('/tickets/{ticket}/start', [MobileTechnicianController::class, 'start']);
        Route::get('/stock-items', [MobileTechnicianController::class, 'stockItems']);
        Route::post('/tickets/{ticket}/part-requests', [MobileTechnicianController::class, 'requestPart']);
        Route::post('/tickets/{ticket}/signature', [MobileTechnicianController::class, 'sign']);
    });

    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:5,1,legacy-login:');
    Route::name('machine.')->middleware(['nexa.bearer', 'auth:sanctum', 'nexa.tenant', 'nexa.portfolio'])->group(
        static function () use ($registerBackoffice): void {
            Route::get('/auth/me', [AuthController::class, 'me']);
            Route::post('/auth/logout', [AuthController::class, 'logout']);
            $registerBackoffice();
        }
    );

    Route::prefix('browser')->name('browser.')->middleware('web')->group(
        static function () use ($registerBackoffice): void {
            Route::get('/auth/csrf', [BrowserAuthController::class, 'csrf'])->middleware('throttle:120,1,browser-csrf:');
            Route::post('/auth/login', [BrowserAuthController::class, 'login'])->middleware('throttle:5,1,browser-login:');
            Route::post('/auth/activate', AccountActivationController::class)->middleware('throttle:5,1,browser-activate:');

            Route::middleware(['auth:web', 'nexa.browser-tenant', 'nexa.portfolio'])->group(
                static function () use ($registerBackoffice): void {
                    Route::get('/auth/me', [AuthController::class, 'me']);
                    Route::post('/auth/logout', [BrowserAuthController::class, 'logout']);
                    // Read-only customer portal; never expose the backoffice to this role.
                    Route::get('/client/printers', ClientPrinterController::class)
                        ->middleware('nexa.roles:customer');
                    Route::get('/client/summary', ClientFleetSummaryController::class)
                        ->middleware('nexa.roles:customer');
                    Route::get('/client/analytics', ClientFleetAnalyticsController::class)
                        ->middleware('nexa.roles:customer');
                    Route::middleware('nexa.roles:customer')->group(static function (): void {
                        Route::get('/client/tickets', [ClientTicketController::class, 'index']);
                        Route::post('/client/tickets', [ClientTicketController::class, 'store'])
                            ->middleware('throttle:5,1,client-tickets:');
                        Route::get('/client/tickets/{ticket}', [ClientTicketController::class, 'show']);
                        Route::get('/client/tickets/{ticket}/attachments/{attachment}', [ClientTicketController::class, 'download']);
                    });
                    $registerBackoffice();
                }
            );
        }
    );
});
