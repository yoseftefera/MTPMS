<?php

use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| API Routes — v1
|--------------------------------------------------------------------------
| Rate limiters ('auth' and 'api') are registered in AppServiceProvider.
| See App\Providers\AppServiceProvider::boot() for their definitions.
|--------------------------------------------------------------------------
*/

// ── Health check — top-level, no auth, no tenant required ────────────────────
// Requirement 20.10: expose at /api/health (outside the /v1 prefix)
Route::get('/health', [\App\Http\Controllers\Api\V1\HealthController::class, 'health'])
    ->withoutMiddleware([\App\Http\Middleware\TenantIdentificationMiddleware::class]);

Route::prefix('v1')->group(function () {

    // ── Authentication (public, rate-limited) ────────────────────────────────
    Route::prefix('auth')->middleware('throttle:auth')->group(function () {
        Route::post('/login',                   [\App\Http\Controllers\Api\V1\AuthController::class, 'login']);
        Route::post('/password/reset-request',  [\App\Http\Controllers\Api\V1\AuthController::class, 'requestPasswordReset']);
        Route::post('/password/reset-confirm',  [\App\Http\Controllers\Api\V1\AuthController::class, 'resetPassword']);
        Route::get('/csrf-token',               [\App\Http\Controllers\Api\V1\AuthController::class, 'csrfToken']);

        Route::middleware('auth.jwt')->group(function () {
            Route::post('/logout',  [\App\Http\Controllers\Api\V1\AuthController::class, 'logout']);
            Route::post('/refresh', [\App\Http\Controllers\Api\V1\AuthController::class, 'refresh']);
            Route::get('/me',       [\App\Http\Controllers\Api\V1\AuthController::class, 'me']);
        });
    });

    // ── Supplier self-registration (public) ──────────────────────────────────
    Route::post('/suppliers/register', [\App\Http\Controllers\Api\V1\SupplierController::class, 'register']);

    // ── Protected API routes ─────────────────────────────────────────────────
    Route::middleware(['auth.jwt', 'throttle:api'])->group(function () {

        // System Admin — Tenant management
        Route::middleware('role.check:audit_logs.view')->group(function () {
            Route::apiResource('tenants', \App\Http\Controllers\Api\V1\TenantController::class);
            Route::patch('tenants/{tenant}/status', [\App\Http\Controllers\Api\V1\TenantController::class, 'updateStatus']);
        });

        // User management (view/create/update/delete)
        Route::middleware('role.check:users.view')->group(function () {
            Route::get('users',        [\App\Http\Controllers\Api\V1\UserController::class, 'index']);
            Route::get('users/{user}', [\App\Http\Controllers\Api\V1\UserController::class, 'show']);
        });
        Route::post('users',           [\App\Http\Controllers\Api\V1\UserController::class, 'store'])->middleware('role.check:users.create');
        Route::put('users/{user}',     [\App\Http\Controllers\Api\V1\UserController::class, 'update'])->middleware('role.check:users.update');
        Route::patch('users/{user}',   [\App\Http\Controllers\Api\V1\UserController::class, 'update'])->middleware('role.check:users.update');
        Route::delete('users/{user}',  [\App\Http\Controllers\Api\V1\UserController::class, 'destroy'])->middleware('role.check:users.delete');

        // Role assignment/revocation — requires roles.assign permission (Tenant_Admin only)
        Route::middleware('role.check:roles.assign')->group(function () {
            Route::post('users/{user}/roles',          [\App\Http\Controllers\Api\V1\UserController::class, 'assignRole']);
            Route::delete('users/{user}/roles/{role}', [\App\Http\Controllers\Api\V1\UserController::class, 'revokeRole']);
        });

        // Department management
        Route::middleware('role.check:departments.view')->group(function () {
            // Hierarchy must be registered before the resource routes so it is not
            // mistaken for a {department} wildcard segment.
            Route::get('departments/hierarchy', [\App\Http\Controllers\Api\V1\DepartmentController::class, 'hierarchy']);
            Route::apiResource('departments', \App\Http\Controllers\Api\V1\DepartmentController::class);
            Route::patch('departments/{department}/status', [\App\Http\Controllers\Api\V1\DepartmentController::class, 'updateStatus']);
        });

        // Budget management
        // utilization-report and transfer are registered before {budget} wildcard to avoid route conflicts.
        Route::middleware('role.check:budgets.view')->prefix('budgets')->group(function () {
            Route::get('/',                   [\App\Http\Controllers\Api\V1\BudgetController::class, 'index']);
            Route::post('/',                  [\App\Http\Controllers\Api\V1\BudgetController::class, 'store'])->middleware('role.check:budgets.create');
            Route::get('/utilization-report', [\App\Http\Controllers\Api\V1\BudgetController::class, 'utilizationReport']);
            Route::post('/transfer',          [\App\Http\Controllers\Api\V1\BudgetController::class, 'transfer'])->middleware('role.check:budgets.create');
            Route::get('/{budget}',           [\App\Http\Controllers\Api\V1\BudgetController::class, 'show']);
            Route::put('/{budget}',           [\App\Http\Controllers\Api\V1\BudgetController::class, 'update'])->middleware('role.check:budgets.create');
        });

        // Purchase requests
        Route::middleware('role.check:purchase_requests.view')->prefix('purchase-requests')->group(function () {
            Route::get('/',                                    [\App\Http\Controllers\Api\V1\PurchaseRequestController::class, 'index']);
            Route::post('/',                                   [\App\Http\Controllers\Api\V1\PurchaseRequestController::class, 'store']);
            Route::get('/{purchaseRequest}',                   [\App\Http\Controllers\Api\V1\PurchaseRequestController::class, 'show']);
            Route::put('/{purchaseRequest}',                   [\App\Http\Controllers\Api\V1\PurchaseRequestController::class, 'update']);
            Route::post('/{purchaseRequest}/submit',           [\App\Http\Controllers\Api\V1\PurchaseRequestController::class, 'submit']);
            Route::post('/{purchaseRequest}/cancel',           [\App\Http\Controllers\Api\V1\PurchaseRequestController::class, 'cancel']);
            Route::post('/{purchaseRequest}/documents',        [\App\Http\Controllers\Api\V1\PurchaseRequestController::class, 'attachDocument']);
            Route::delete('/{purchaseRequest}',                [\App\Http\Controllers\Api\V1\PurchaseRequestController::class, 'destroy']);
        });

        // Approval workflow configuration — Tenant_Admin only
        Route::middleware('role.check:workflows.manage')->prefix('approval-workflows')->group(function () {
            Route::get('/',                                                     [\App\Http\Controllers\Api\V1\ApprovalWorkflowController::class, 'index']);
            Route::post('/',                                                    [\App\Http\Controllers\Api\V1\ApprovalWorkflowController::class, 'store']);
            Route::get('/{approvalWorkflow}',                                   [\App\Http\Controllers\Api\V1\ApprovalWorkflowController::class, 'show']);
            Route::put('/{approvalWorkflow}',                                   [\App\Http\Controllers\Api\V1\ApprovalWorkflowController::class, 'update']);
            Route::delete('/{approvalWorkflow}',                                [\App\Http\Controllers\Api\V1\ApprovalWorkflowController::class, 'destroy']);
            Route::post('/{approvalWorkflow}/levels',                           [\App\Http\Controllers\Api\V1\ApprovalWorkflowController::class, 'addLevel']);
            Route::delete('/{approvalWorkflow}/levels/{levelId}',               [\App\Http\Controllers\Api\V1\ApprovalWorkflowController::class, 'removeLevel']);
        });

        // Approval actions — any authenticated user (approver)
        // pending and history must be registered before the {approval} wildcard to avoid conflicts
        Route::prefix('approvals')->group(function () {
            Route::get('/pending',                                              [\App\Http\Controllers\Api\V1\ApprovalController::class, 'pending']);
            Route::get('/history/{documentType}/{documentId}',                  [\App\Http\Controllers\Api\V1\ApprovalController::class, 'history']);
            Route::post('/{approval}/approve',                                  [\App\Http\Controllers\Api\V1\ApprovalController::class, 'approve']);
            Route::post('/{approval}/reject',                                   [\App\Http\Controllers\Api\V1\ApprovalController::class, 'reject']);
            Route::post('/{approval}/return',                                   [\App\Http\Controllers\Api\V1\ApprovalController::class, 'return']);
        });

        // Supplier management
        Route::middleware('role.check:suppliers.view')->group(function () {
            Route::apiResource('suppliers', \App\Http\Controllers\Api\V1\SupplierController::class)->except(['store']);
            Route::post('suppliers/{supplier}/approve',    [\App\Http\Controllers\Api\V1\SupplierController::class, 'approve']);
            Route::post('suppliers/{supplier}/reject',     [\App\Http\Controllers\Api\V1\SupplierController::class, 'reject']);
            Route::post('suppliers/{supplier}/blacklist',  [\App\Http\Controllers\Api\V1\SupplierController::class, 'blacklist']);
            Route::get('suppliers/{supplier}/performance', [\App\Http\Controllers\Api\V1\SupplierController::class, 'performance']);
            Route::post('suppliers/{supplier}/documents',  [\App\Http\Controllers\Api\V1\SupplierController::class, 'uploadDocument']);
        });

        // Tenders
        Route::middleware('role.check:tenders.view')->group(function () {
            Route::apiResource('tenders', \App\Http\Controllers\Api\V1\TenderController::class);
            Route::post('tenders/{tender}/publish',   [\App\Http\Controllers\Api\V1\TenderController::class, 'publish']);
            Route::post('tenders/{tender}/cancel',    [\App\Http\Controllers\Api\V1\TenderController::class, 'cancel']);
            Route::patch('tenders/{tender}/deadline', [\App\Http\Controllers\Api\V1\TenderController::class, 'extendDeadline']);
            Route::post('tenders/{tender}/documents', [\App\Http\Controllers\Api\V1\TenderController::class, 'uploadDocument']);
        });

        // Bids
        Route::get('tenders/{tender}/bids',                    [\App\Http\Controllers\Api\V1\BidController::class, 'index']);
        Route::post('tenders/{tender}/bids',                   [\App\Http\Controllers\Api\V1\BidController::class, 'store'])->middleware('role.check:invoices.submit');
        Route::get('tenders/{tender}/bids/{bid}',              [\App\Http\Controllers\Api\V1\BidController::class, 'show']);
        Route::patch('tenders/{tender}/bids/{bid}',            [\App\Http\Controllers\Api\V1\BidController::class, 'update'])->middleware('role.check:invoices.submit');
        Route::post('tenders/{tender}/bids/{bid}/documents',   [\App\Http\Controllers\Api\V1\BidController::class, 'uploadDocument'])->middleware('role.check:invoices.submit');

        // Bid evaluation — criteria (view: all three roles; write: Procurement_Officer + Tenant_Admin)
        Route::get('tenders/{tender}/evaluation/criteria',
            [\App\Http\Controllers\Api\V1\BidEvaluationController::class, 'criteria']);
        Route::post('tenders/{tender}/evaluation/criteria',
            [\App\Http\Controllers\Api\V1\BidEvaluationController::class, 'storeCriteria'])
            ->middleware('role.check:tenders.create');

        // Bid evaluation — score submission (Committee_Member + Tenant_Admin)
        Route::post('tenders/{tender}/bids/{bid}/evaluation/scores',
            [\App\Http\Controllers\Api\V1\BidEvaluationController::class, 'submitScore'])
            ->middleware('role.check:tenders.evaluate');

        // Bid evaluation — ranked comparison (read-only, all three roles)
        Route::get('tenders/{tender}/evaluation/rankings',
            [\App\Http\Controllers\Api\V1\BidEvaluationController::class, 'rankings'])
            ->middleware('role.check:tenders.evaluate');

        // Bid evaluation — winner selection (Procurement_Officer + Tenant_Admin)
        Route::post('tenders/{tender}/evaluation/winner',
            [\App\Http\Controllers\Api\V1\BidEvaluationController::class, 'selectWinner'])
            ->middleware('role.check:tenders.publish');

        // Purchase orders
        Route::middleware('role.check:purchase_orders.view')->group(function () {
            Route::apiResource('purchase-orders', \App\Http\Controllers\Api\V1\PurchaseOrderController::class);
            Route::post('purchase-orders/{purchaseOrder}/issue',  [\App\Http\Controllers\Api\V1\PurchaseOrderController::class, 'issue']);
            Route::post('purchase-orders/{purchaseOrder}/cancel', [\App\Http\Controllers\Api\V1\PurchaseOrderController::class, 'cancel']);
        });
        Route::post('purchase-orders/{purchaseOrder}/accept', [\App\Http\Controllers\Api\V1\PurchaseOrderController::class, 'accept'])->middleware('role.check:purchase_orders.approve');
        Route::post('purchase-orders/{purchaseOrder}/reject', [\App\Http\Controllers\Api\V1\PurchaseOrderController::class, 'reject'])->middleware('role.check:purchase_orders.approve');

        // Contracts
        Route::middleware('role.check:contracts.view')->group(function () {
            Route::apiResource('contracts', \App\Http\Controllers\Api\V1\ContractController::class);
            Route::post('contracts/{contract}/activate',  [\App\Http\Controllers\Api\V1\ContractController::class, 'activate']);
            Route::post('contracts/{contract}/amend',     [\App\Http\Controllers\Api\V1\ContractController::class, 'amend']);
            Route::post('contracts/{contract}/terminate', [\App\Http\Controllers\Api\V1\ContractController::class, 'terminate']);
            Route::post('contracts/{contract}/documents', [\App\Http\Controllers\Api\V1\ContractController::class, 'uploadDocument']);
        });

        // Goods receipts
        Route::middleware('role.check:purchase_orders.view')->prefix('goods-receipts')->group(function () {
            Route::get('/',                                                         [\App\Http\Controllers\Api\V1\GoodsReceiptController::class, 'index']);
            Route::post('/',                                                        [\App\Http\Controllers\Api\V1\GoodsReceiptController::class, 'store']);
            Route::get('/{goodsReceipt}',                                           [\App\Http\Controllers\Api\V1\GoodsReceiptController::class, 'show']);
            Route::post('/{goodsReceipt}/assign-committee',                         [\App\Http\Controllers\Api\V1\GoodsReceiptController::class, 'assignCommittee']);
            Route::post('/{goodsReceipt}/inspection-result',                        [\App\Http\Controllers\Api\V1\GoodsReceiptController::class, 'submitInspectionResult'])->middleware('role.check:tenders.evaluate');
        });

        // Inventory
        Route::middleware('role.check:purchase_orders.view')->prefix('inventory')->group(function () {
            Route::get('/',              [\App\Http\Controllers\Api\V1\InventoryController::class, 'index']);
            Route::get('/{inventory}',   [\App\Http\Controllers\Api\V1\InventoryController::class, 'show']);
        });

        // Invoices
        Route::middleware('role.check:invoices.view')->prefix('invoices')->group(function () {
            Route::get('/',                             [\App\Http\Controllers\Api\V1\InvoiceController::class, 'index']);
            Route::post('/',                            [\App\Http\Controllers\Api\V1\InvoiceController::class, 'store']);
            Route::get('/{invoice}',                    [\App\Http\Controllers\Api\V1\InvoiceController::class, 'show']);
            Route::post('/{invoice}/approve',           [\App\Http\Controllers\Api\V1\InvoiceController::class, 'approve'])->middleware('role.check:invoices.approve');
            Route::post('/{invoice}/reject',            [\App\Http\Controllers\Api\V1\InvoiceController::class, 'reject'])->middleware('role.check:invoices.approve');
        });

        // Payments — schedule must be registered before {payment} wildcard to avoid route conflicts
        Route::middleware('role.check:payments.process')->prefix('payments')->group(function () {
            Route::get('/',             [\App\Http\Controllers\Api\V1\PaymentController::class, 'index']);
            Route::get('/schedule',     [\App\Http\Controllers\Api\V1\PaymentController::class, 'schedule']);
            Route::get('/{payment}',    [\App\Http\Controllers\Api\V1\PaymentController::class, 'show']);
            Route::post('/{payment}/record', [\App\Http\Controllers\Api\V1\PaymentController::class, 'record']);
        });

        // Notifications
        // Static segments must be registered before the {notification} wildcard to avoid conflicts.
        Route::get('notifications',                          [\App\Http\Controllers\Api\V1\NotificationController::class, 'index']);
        Route::get('notifications/unread-count',             [\App\Http\Controllers\Api\V1\NotificationController::class, 'unreadCount']);
        Route::patch('notifications/read-all',               [\App\Http\Controllers\Api\V1\NotificationController::class, 'markAllAsRead']);
        Route::patch('notifications/{notification}/read',    [\App\Http\Controllers\Api\V1\NotificationController::class, 'markAsRead']);

        // Reports
        Route::middleware('role.check:reports.view')->group(function () {
            Route::get('reports/dashboard',             [\App\Http\Controllers\Api\V1\ReportController::class, 'dashboard']);
            Route::get('reports/procurement-timeline',  [\App\Http\Controllers\Api\V1\ReportController::class, 'procurementTimeline']);
            Route::get('reports/spending-analytics',    [\App\Http\Controllers\Api\V1\ReportController::class, 'spendingAnalytics']);
            Route::get('reports/supplier-performance',  [\App\Http\Controllers\Api\V1\ReportController::class, 'supplierPerformance']);
            Route::get('reports/tender-statistics',     [\App\Http\Controllers\Api\V1\ReportController::class, 'tenderStatistics']);
            Route::get('reports/financial-summary',     [\App\Http\Controllers\Api\V1\ReportController::class, 'financialSummary']);
            Route::post('reports/export',               [\App\Http\Controllers\Api\V1\ReportController::class, 'export']);
        });

        // Audit logs
        // GET  is role-guarded; PUT/DELETE are open (controller always returns 403 — immutability enforcement).
        Route::middleware('role.check:audit_logs.view')->group(function () {
            Route::get('audit-logs', [\App\Http\Controllers\Api\V1\AuditLogController::class, 'index']);
        });
        Route::put('audit-logs/{id}',    [\App\Http\Controllers\Api\V1\AuditLogController::class, 'update']);
        Route::patch('audit-logs/{id}',  [\App\Http\Controllers\Api\V1\AuditLogController::class, 'update']);
        Route::delete('audit-logs/{id}', [\App\Http\Controllers\Api\V1\AuditLogController::class, 'destroy']);

        // File management
        // GET  /api/v1/files/download?path={base64EncodedPath}  — stream file (tenant-verified)
        // DELETE /api/v1/files/{file}                            — soft-delete via audit log
        // The static 'download' segment must be registered before the {file} wildcard.
        Route::get('files/download',  [\App\Http\Controllers\Api\V1\FileController::class, 'download']);
        Route::delete('files/{file}', [\App\Http\Controllers\Api\V1\FileController::class, 'destroy']);
    });
});
