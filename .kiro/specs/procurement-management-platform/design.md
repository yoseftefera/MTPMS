# Design Document

## Procurement Management Platform (PMP)

---

## Overview

The Procurement Management Platform (PMP) is a production-ready, enterprise-grade, multi-tenant SaaS application that automates the full procurement lifecycle. It serves multiple independent organizations (tenants) on shared infrastructure while enforcing strict data isolation between them.

The platform covers end-to-end procurement: purchase request creation, multi-level approval workflows, supplier management, tender and bidding, bid evaluation, purchase order management, contract lifecycle management, goods receiving, budget tracking, invoice and payment processing, reporting and analytics, real-time notifications, and immutable audit logging.

**Tech Stack Summary:**
- **Backend**: Laravel 12, PHP 8.3+, JWT (tymon/jwt-auth), Spatie Laravel Permission, Redis, Laravel Echo/Soketi
- **Frontend**: Next.js 15 (App Router), TypeScript, Tailwind CSS, ShadCN UI, TanStack Query, Zustand, Recharts, Framer Motion
- **Database**: MySQL 8.0+, UUID primary keys, DECIMAL(15,2) monetary values, soft deletes
- **Mobile**: Flutter 3.x, Clean Architecture, Riverpod state management
- **Infrastructure**: Docker, Docker Compose, Nginx, Redis, GitHub Actions CI/CD

---

## Architecture

### Three-Tier Architecture

The platform follows a strict three-tier architecture:

```
┌─────────────────────────────────────────────────────────────────┐
│                    PRESENTATION TIER                            │
│  ┌─────────────────────┐    ┌──────────────────────────────┐   │
│  │   Next.js 15 Web    │    │    Flutter Mobile App        │   │
│  │   (App Router)      │    │    (Supplier Portal)         │   │
│  └─────────────────────┘    └──────────────────────────────┘   │
└─────────────────────────────────────────────────────────────────┘
                              │ HTTPS /api/v1
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                    APPLICATION TIER                             │
│  ┌──────────────────────────────────────────────────────────┐  │
│  │                    Nginx Reverse Proxy                   │  │
│  └──────────────────────────────────────────────────────────┘  │
│  ┌──────────────────────────────────────────────────────────┐  │
│  │              Laravel 12 Application                      │  │
│  │  ┌─────────────┐  ┌──────────────┐  ┌────────────────┐  │  │
│  │  │  Middleware  │  │  Controllers │  │  Service Layer │  │  │
│  │  │  Stack       │  │  /api/v1     │  │  (Business     │  │  │
│  │  │              │  │              │  │   Logic)       │  │  │
│  │  └─────────────┘  └──────────────┘  └────────────────┘  │  │
│  │  ┌─────────────┐  ┌──────────────┐  ┌────────────────┐  │  │
│  │  │  Repository  │  │  Queue Jobs  │  │  Event/        │  │  │
│  │  │  Pattern     │  │  (Redis)     │  │  Listeners     │  │  │
│  │  └─────────────┘  └──────────────┘  └────────────────┘  │  │
│  └──────────────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│                       DATA TIER                                 │
│  ┌──────────────────────┐    ┌──────────────────────────────┐  │
│  │   MySQL 8.0+         │    │   Redis                      │  │
│  │   (Multi-tenant DB)  │    │   (Cache / Queue / Session)  │  │
│  └──────────────────────┘    └──────────────────────────────┘  │
└─────────────────────────────────────────────────────────────────┘
```

### Multi-Tenant Architecture

The platform uses a **shared-schema, tenant-scoped** approach. All tenants share a single MySQL database schema, with every tenant-scoped table carrying a `tenant_id` UUID foreign key. Tenant resolution happens at the middleware layer on every request.

```
┌─────────────────────────────────────────────────────────────────┐
│                    MySQL Database                               │
│                                                                 │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │  Master Tables (cross-tenant)                           │   │
│  │  tenants | system_admins | system_audit_logs            │   │
│  └─────────────────────────────────────────────────────────┘   │
│                                                                 │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │  Tenant-Scoped Tables (all have tenant_id FK)           │   │
│  │  users | departments | budgets | purchase_requests      │   │
│  │  suppliers | tenders | bids | purchase_orders           │   │
│  │  contracts | invoices | payments | inventory            │   │
│  │  notifications | audit_logs | ...                       │   │
│  └─────────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────────┘
```

**Tenant Resolution Flow:**

```
Request → TenantIdentificationMiddleware
            ├── Check X-Tenant-ID header
            ├── Check subdomain (tenant.platform.com)
            └── Check JWT claim (tenant_id)
                    │
                    ▼
            Resolve Tenant from DB/Cache
                    │
              ┌─────┴──────┐
              │ Not Found  │ → HTTP 401 + Audit Log
              └────────────┘
                    │
              ┌─────┴──────┐
              │ Suspended  │ → HTTP 401
              └────────────┘
                    │
              Set app('tenant') context
                    │
              All subsequent queries auto-scoped via GlobalScope
```

### Component Interaction Flow

```
Client Request
    │
    ▼
Nginx (TLS termination, security headers)
    │
    ▼
Laravel Middleware Stack:
  1. TenantIdentificationMiddleware  → resolves & sets tenant context
  2. AuthMiddleware (JWT)            → validates token, sets auth user
  3. RoleMiddleware (Spatie)         → checks permission for route
  4. AuditTrailMiddleware            → captures request metadata
    │
    ▼
Controller (thin - validates HTTP, delegates to Service)
    │
    ▼
Service Layer (business logic, orchestration)
    │
    ├── Repository Layer (data access, Eloquent ORM)
    │       │
    │       └── MySQL (queries auto-scoped by TenantScope)
    │
    ├── Queue Jobs (async: notifications, reports, audit writes)
    │       │
    │       └── Redis Queue
    │
    └── Events → Listeners → Notifications (WebSocket + Email)
```


---

## Components and Interfaces

### Backend Directory Structure

```
app/
├── Console/
│   └── Commands/
│       ├── ProcessEscalations.php
│       └── SendContractRenewalAlerts.php
├── Events/
│   ├── PurchaseRequestSubmitted.php
│   ├── PurchaseRequestApproved.php
│   ├── TenderPublished.php
│   ├── BidSubmitted.php
│   ├── PurchaseOrderIssued.php
│   ├── InvoiceSubmitted.php
│   ├── PaymentProcessed.php
│   └── BudgetThresholdReached.php
├── Exceptions/
│   ├── TenantNotFoundException.php
│   ├── BudgetExceededException.php
│   ├── UnauthorizedTenantAccessException.php
│   └── Handler.php
├── Http/
│   ├── Controllers/
│   │   └── Api/
│   │       └── V1/
│   │           ├── AuthController.php
│   │           ├── TenantController.php
│   │           ├── UserController.php
│   │           ├── DepartmentController.php
│   │           ├── BudgetController.php
│   │           ├── PurchaseRequestController.php
│   │           ├── ApprovalWorkflowController.php
│   │           ├── SupplierController.php
│   │           ├── TenderController.php
│   │           ├── BidController.php
│   │           ├── BidEvaluationController.php
│   │           ├── PurchaseOrderController.php
│   │           ├── ContractController.php
│   │           ├── GoodsReceiptController.php
│   │           ├── InventoryController.php
│   │           ├── InvoiceController.php
│   │           ├── PaymentController.php
│   │           ├── NotificationController.php
│   │           ├── ReportController.php
│   │           ├── AuditLogController.php
│   │           └── FileController.php
│   ├── Middleware/
│   │   ├── TenantIdentificationMiddleware.php
│   │   ├── AuthMiddleware.php
│   │   ├── RoleMiddleware.php
│   │   └── AuditTrailMiddleware.php
│   ├── Requests/
│   │   └── V1/
│   │       ├── Auth/LoginRequest.php
│   │       ├── PurchaseRequest/StorePurchaseRequestRequest.php
│   │       └── ... (one FormRequest per endpoint)
│   └── Resources/
│       └── V1/
│           ├── UserResource.php
│           ├── PurchaseRequestResource.php
│           └── ... (one Resource per entity)
├── Jobs/
│   ├── SendNotificationEmailJob.php
│   ├── GenerateReportJob.php
│   ├── WriteAuditLogJob.php
│   └── ProcessSupplierDocumentScanJob.php
├── Listeners/
│   ├── SendPurchaseRequestNotification.php
│   ├── TriggerApprovalWorkflow.php
│   ├── NotifySupplierOnTenderPublished.php
│   └── UpdateSupplierPerformanceMetrics.php
├── Models/
│   ├── Tenant.php
│   ├── User.php
│   ├── Department.php
│   ├── Budget.php
│   ├── BudgetTransaction.php
│   ├── PurchaseRequest.php
│   ├── PurchaseRequestItem.php
│   ├── PurchaseRequestHistory.php
│   ├── ApprovalWorkflow.php
│   ├── ApprovalWorkflowLevel.php
│   ├── Approval.php
│   ├── Supplier.php
│   ├── SupplierDocument.php
│   ├── SupplierPerformance.php
│   ├── Tender.php
│   ├── TenderDocument.php
│   ├── Bid.php
│   ├── BidDocument.php
│   ├── BidEvaluationCriteria.php
│   ├── BidEvaluation.php
│   ├── PurchaseOrder.php
│   ├── PurchaseOrderItem.php
│   ├── Contract.php
│   ├── ContractAmendment.php
│   ├── ContractDocument.php
│   ├── GoodsReceipt.php
│   ├── GoodsReceiptItem.php
│   ├── Inventory.php
│   ├── Warehouse.php
│   ├── Invoice.php
│   ├── InvoiceItem.php
│   ├── Payment.php
│   ├── Notification.php
│   ├── AuditLog.php
│   ├── MarketResearch.php
│   └── MarketResearchItem.php
├── Repositories/
│   ├── Contracts/
│   │   ├── PurchaseRequestRepositoryInterface.php
│   │   ├── BudgetRepositoryInterface.php
│   │   └── ... (interface per repository)
│   ├── PurchaseRequestRepository.php
│   ├── BudgetRepository.php
│   └── ... (implementation per repository)
├── Services/
│   ├── AuthService.php
│   ├── TenantService.php
│   ├── UserManagementService.php
│   ├── RBACService.php
│   ├── PurchaseRequestService.php
│   ├── ApprovalWorkflowService.php
│   ├── SupplierManagementService.php
│   ├── TenderService.php
│   ├── BidEvaluationService.php
│   ├── PurchaseOrderService.php
│   ├── ContractService.php
│   ├── GoodsReceiptService.php
│   ├── InventoryService.php
│   ├── BudgetService.php
│   ├── InvoiceService.php
│   ├── PaymentService.php
│   ├── NotificationService.php
│   ├── ReportingService.php
│   ├── AuditService.php
│   └── FileManagementService.php
└── Traits/
    ├── HasTenantScope.php
    ├── GeneratesDocumentNumber.php
    └── HasAuditLog.php
```

### Middleware Stack

| Middleware | Responsibility | Applied To |
|---|---|---|
| `TenantIdentificationMiddleware` | Resolves tenant from subdomain/header/JWT, sets app context | All routes |
| `AuthMiddleware` | Validates JWT, loads authenticated user | All protected routes |
| `RoleMiddleware` | Checks Spatie permission for the route | All protected routes |
| `AuditTrailMiddleware` | Captures request metadata for audit log | All state-changing routes |
| `ThrottleRequests` | Rate limiting (60/min auth, 300/min API) | Auth + API routes |

### Service Layer Interfaces

Each service follows this pattern:

```php
// Example: PurchaseRequestService
interface PurchaseRequestServiceInterface {
    public function create(array $data, User $submitter): PurchaseRequest;
    public function submit(PurchaseRequest $pr): PurchaseRequest;
    public function approve(PurchaseRequest $pr, User $approver, string $comment): PurchaseRequest;
    public function reject(PurchaseRequest $pr, User $approver, string $reason): PurchaseRequest;
    public function returnForRevision(PurchaseRequest $pr, User $approver, string $comments): PurchaseRequest;
    public function generatePRNumber(Tenant $tenant): string;
    public function search(array $filters, int $perPage): LengthAwarePaginator;
}
```

### Repository Pattern

```php
// Base repository with tenant scoping
abstract class BaseRepository {
    protected Model $model;

    public function __construct(protected TenantContext $tenantContext) {}

    protected function query(): Builder {
        return $this->model->newQuery()
            ->where('tenant_id', $this->tenantContext->getTenantId());
    }

    public function findById(string $uuid): ?Model {
        return $this->query()->find($uuid);
    }

    public function paginate(array $filters, int $perPage = 20): LengthAwarePaginator {
        return $this->applyFilters($this->query(), $filters)->paginate($perPage);
    }
}
```

### Queue Configuration

Three named queues with priority ordering:

```php
// config/queue.php
'connections' => [
    'redis' => [
        'driver' => 'redis',
        'queues' => ['notifications', 'default', 'reports'],
    ]
]
```

| Queue | Priority | Jobs |
|---|---|---|
| `notifications` | High | `SendNotificationEmailJob`, WebSocket broadcasts |
| `default` | Medium | `WriteAuditLogJob`, `ProcessSupplierDocumentScanJob` |
| `reports` | Low | `GenerateReportJob` |

### Event/Listener Architecture

```
PurchaseRequestSubmitted
    ├── TriggerApprovalWorkflow
    └── SendPurchaseRequestNotification

TenderPublished
    └── NotifySupplierOnTenderPublished (dispatches email jobs per supplier)

BidEvaluationCompleted
    └── NotifyBidOutcomes (winner + non-winners)

PurchaseOrderIssued
    └── SendPOToSupplier (email + portal notification)

BudgetThresholdReached
    └── NotifyBudgetStakeholders

ContractExpiryApproaching
    └── SendContractRenewalAlert
```


---

## Data Models

### Master Database Tables

#### `tenants`
| Column | Type | Constraints |
|---|---|---|
| `id` | UUID | PK |
| `name` | VARCHAR(255) | NOT NULL |
| `subdomain` | VARCHAR(100) | UNIQUE, NOT NULL |
| `admin_email` | VARCHAR(255) | NOT NULL |
| `status` | ENUM('active','suspended','deactivated') | DEFAULT 'active' |
| `tenant_code` | VARCHAR(10) | UNIQUE, NOT NULL |
| `settings` | JSON | nullable (password rules, session timeout, etc.) |
| `created_at` | TIMESTAMP | |
| `updated_at` | TIMESTAMP | |
| `deleted_at` | TIMESTAMP | nullable (soft delete) |

**Indexes:** `subdomain`, `status`, `tenant_code`

#### `system_admins`
| Column | Type | Constraints |
|---|---|---|
| `id` | UUID | PK |
| `name` | VARCHAR(255) | NOT NULL |
| `email` | VARCHAR(255) | UNIQUE, NOT NULL |
| `password` | VARCHAR(255) | NOT NULL (bcrypt) |
| `created_at` | TIMESTAMP | |
| `updated_at` | TIMESTAMP | |

### Tenant-Scoped Tables

All tenant-scoped tables include `tenant_id UUID NOT NULL REFERENCES tenants(id)`.

#### `users`
| Column | Type | Constraints |
|---|---|---|
| `id` | UUID | PK |
| `tenant_id` | UUID | FK → tenants(id) |
| `name` | VARCHAR(255) | NOT NULL |
| `email` | VARCHAR(255) | NOT NULL |
| `password` | VARCHAR(255) | NOT NULL |
| `department_id` | UUID | FK → departments(id), nullable |
| `status` | ENUM('active','inactive','locked') | DEFAULT 'active' |
| `failed_login_attempts` | TINYINT | DEFAULT 0 |
| `avatar` | VARCHAR(500) | nullable |
| `phone` | VARCHAR(50) | nullable |
| `email_verified_at` | TIMESTAMP | nullable |
| `created_at` | TIMESTAMP | |
| `updated_at` | TIMESTAMP | |
| `deleted_at` | TIMESTAMP | nullable |

**Unique:** `(tenant_id, email)` | **Indexes:** `tenant_id`, `department_id`, `status`

#### `departments`
| Column | Type | Constraints |
|---|---|---|
| `id` | UUID | PK |
| `tenant_id` | UUID | FK → tenants(id) |
| `name` | VARCHAR(255) | NOT NULL |
| `code` | VARCHAR(20) | NOT NULL |
| `parent_id` | UUID | FK → departments(id), nullable |
| `status` | ENUM('active','inactive') | DEFAULT 'active' |
| `created_at` | TIMESTAMP | |
| `updated_at` | TIMESTAMP | |
| `deleted_at` | TIMESTAMP | nullable |

**Unique:** `(tenant_id, code)` | **Indexes:** `tenant_id`, `status`

#### `budgets`
| Column | Type | Constraints |
|---|---|---|
| `id` | UUID | PK |
| `tenant_id` | UUID | FK → tenants(id) |
| `department_id` | UUID | FK → departments(id) |
| `fiscal_year` | YEAR | NOT NULL |
| `currency` | CHAR(3) | DEFAULT 'USD' |
| `total_amount` | DECIMAL(15,2) | NOT NULL |
| `encumbered_amount` | DECIMAL(15,2) | DEFAULT 0.00 |
| `spent_amount` | DECIMAL(15,2) | DEFAULT 0.00 |
| `created_by` | UUID | FK → users(id) |
| `created_at` | TIMESTAMP | |
| `updated_at` | TIMESTAMP | |

**Unique:** `(tenant_id, department_id, fiscal_year)` | **Indexes:** `tenant_id`, `department_id`, `fiscal_year`

#### `budget_transactions`
| Column | Type | Constraints |
|---|---|---|
| `id` | UUID | PK |
| `tenant_id` | UUID | FK → tenants(id) |
| `budget_id` | UUID | FK → budgets(id) |
| `type` | ENUM('encumber','release','spend','transfer_in','transfer_out') | NOT NULL |
| `amount` | DECIMAL(15,2) | NOT NULL |
| `reference_type` | VARCHAR(50) | NOT NULL (e.g., 'purchase_order') |
| `reference_id` | UUID | NOT NULL |
| `created_by` | UUID | FK → users(id) |
| `created_at` | TIMESTAMP | |

**Indexes:** `tenant_id`, `budget_id`, `reference_type`, `reference_id`

#### `purchase_requests`
| Column | Type | Constraints |
|---|---|---|
| `id` | UUID | PK |
| `tenant_id` | UUID | FK → tenants(id) |
| `pr_number` | VARCHAR(50) | NOT NULL |
| `department_id` | UUID | FK → departments(id) |
| `submitted_by` | UUID | FK → users(id) |
| `status` | ENUM('draft','pending_approval','approved','rejected','revision_required','cancelled') | DEFAULT 'draft' |
| `title` | VARCHAR(255) | NOT NULL |
| `description` | TEXT | nullable |
| `estimated_total` | DECIMAL(15,2) | NOT NULL |
| `currency` | CHAR(3) | DEFAULT 'USD' |
| `required_date` | DATE | nullable |
| `submitted_at` | TIMESTAMP | nullable |
| `created_at` | TIMESTAMP | |
| `updated_at` | TIMESTAMP | |
| `deleted_at` | TIMESTAMP | nullable |

**Unique:** `(tenant_id, pr_number)` | **Indexes:** `tenant_id`, `department_id`, `submitted_by`, `status`, `created_at`

#### `purchase_request_items`
| Column | Type | Constraints |
|---|---|---|
| `id` | UUID | PK |
| `tenant_id` | UUID | FK → tenants(id) |
| `purchase_request_id` | UUID | FK → purchase_requests(id) |
| `description` | VARCHAR(500) | NOT NULL |
| `quantity` | DECIMAL(15,2) | NOT NULL |
| `unit_of_measure` | VARCHAR(50) | NOT NULL |
| `estimated_unit_price` | DECIMAL(15,2) | NOT NULL |
| `budget_code` | VARCHAR(50) | nullable |
| `created_at` | TIMESTAMP | |
| `updated_at` | TIMESTAMP | |

#### `purchase_request_history`
| Column | Type | Constraints |
|---|---|---|
| `id` | UUID | PK |
| `tenant_id` | UUID | FK → tenants(id) |
| `purchase_request_id` | UUID | FK → purchase_requests(id) |
| `action` | VARCHAR(100) | NOT NULL |
| `from_status` | VARCHAR(50) | nullable |
| `to_status` | VARCHAR(50) | nullable |
| `comment` | TEXT | nullable |
| `performed_by` | UUID | FK → users(id) |
| `created_at` | TIMESTAMP | |

#### `approval_workflows`
| Column | Type | Constraints |
|---|---|---|
| `id` | UUID | PK |
| `tenant_id` | UUID | FK → tenants(id) |
| `name` | VARCHAR(255) | NOT NULL |
| `document_type` | ENUM('purchase_request','tender','purchase_order','contract','invoice') | NOT NULL |
| `department_id` | UUID | FK → departments(id), nullable |
| `is_active` | BOOLEAN | DEFAULT true |
| `created_at` | TIMESTAMP | |
| `updated_at` | TIMESTAMP | |

#### `approval_workflow_levels`
| Column | Type | Constraints |
|---|---|---|
| `id` | UUID | PK |
| `tenant_id` | UUID | FK → tenants(id) |
| `workflow_id` | UUID | FK → approval_workflows(id) |
| `level_order` | TINYINT | NOT NULL (1-10) |
| `approver_type` | ENUM('role','user') | NOT NULL |
| `approver_role` | VARCHAR(100) | nullable |
| `approver_user_id` | UUID | FK → users(id), nullable |
| `is_parallel` | BOOLEAN | DEFAULT false |
| `escalation_hours` | SMALLINT | DEFAULT 48 |
| `created_at` | TIMESTAMP | |
| `updated_at` | TIMESTAMP | |

#### `approvals`
| Column | Type | Constraints |
|---|---|---|
| `id` | UUID | PK |
| `tenant_id` | UUID | FK → tenants(id) |
| `workflow_id` | UUID | FK → approval_workflows(id) |
| `level_id` | UUID | FK → approval_workflow_levels(id) |
| `document_type` | VARCHAR(50) | NOT NULL |
| `document_id` | UUID | NOT NULL |
| `approver_id` | UUID | FK → users(id) |
| `action` | ENUM('pending','approved','rejected','returned') | DEFAULT 'pending' |
| `comment` | TEXT | nullable |
| `acted_at` | TIMESTAMP | nullable |
| `created_at` | TIMESTAMP | |
| `updated_at` | TIMESTAMP | |

**Indexes:** `tenant_id`, `document_type`, `document_id`, `approver_id`, `action`


#### `suppliers`
| Column | Type | Constraints |
|---|---|---|
| `id` | UUID | PK |
| `tenant_id` | UUID | FK → tenants(id) |
| `user_id` | UUID | FK → users(id), nullable (linked portal user) |
| `organization_name` | VARCHAR(255) | NOT NULL |
| `contact_name` | VARCHAR(255) | NOT NULL |
| `contact_email` | VARCHAR(255) | NOT NULL |
| `contact_phone` | VARCHAR(50) | nullable |
| `business_category` | VARCHAR(100) | NOT NULL |
| `status` | ENUM('pending_verification','active','blacklisted','inactive') | DEFAULT 'pending_verification' |
| `blacklist_reason` | TEXT | nullable |
| `blacklisted_by` | UUID | FK → users(id), nullable |
| `blacklisted_at` | TIMESTAMP | nullable |
| `on_time_delivery_rate` | DECIMAL(5,2) | DEFAULT 0.00 |
| `quality_acceptance_rate` | DECIMAL(5,2) | DEFAULT 0.00 |
| `created_at` | TIMESTAMP | |
| `updated_at` | TIMESTAMP | |
| `deleted_at` | TIMESTAMP | nullable |

**Indexes:** `tenant_id`, `status`, `business_category`

#### `supplier_documents`
| Column | Type | Constraints |
|---|---|---|
| `id` | UUID | PK |
| `tenant_id` | UUID | FK → tenants(id) |
| `supplier_id` | UUID | FK → suppliers(id) |
| `document_type` | ENUM('tin_certificate','vat_certificate','business_license','performance_bond','other') | NOT NULL |
| `file_path` | VARCHAR(500) | NOT NULL |
| `file_name` | VARCHAR(255) | NOT NULL |
| `expires_at` | DATE | nullable |
| `version` | TINYINT | DEFAULT 1 |
| `uploaded_by` | UUID | FK → users(id) |
| `created_at` | TIMESTAMP | |
| `deleted_at` | TIMESTAMP | nullable |

#### `tenders`
| Column | Type | Constraints |
|---|---|---|
| `id` | UUID | PK |
| `tenant_id` | UUID | FK → tenants(id) |
| `reference_number` | VARCHAR(50) | NOT NULL |
| `title` | VARCHAR(255) | NOT NULL |
| `description` | TEXT | NOT NULL |
| `category` | VARCHAR(100) | NOT NULL |
| `tender_type` | ENUM('open','restricted','single_source') | DEFAULT 'open' |
| `estimated_value` | DECIMAL(15,2) | NOT NULL |
| `submission_deadline` | TIMESTAMP | NOT NULL |
| `status` | ENUM('draft','published','closed','awarded','cancelled') | DEFAULT 'draft' |
| `created_by` | UUID | FK → users(id) |
| `published_at` | TIMESTAMP | nullable |
| `cancellation_reason` | TEXT | nullable |
| `created_at` | TIMESTAMP | |
| `updated_at` | TIMESTAMP | |
| `deleted_at` | TIMESTAMP | nullable |

**Unique:** `(tenant_id, reference_number)` | **Indexes:** `tenant_id`, `status`, `category`, `submission_deadline`

#### `bids`
| Column | Type | Constraints |
|---|---|---|
| `id` | UUID | PK |
| `tenant_id` | UUID | FK → tenants(id) |
| `tender_id` | UUID | FK → tenders(id) |
| `supplier_id` | UUID | FK → suppliers(id) |
| `total_amount` | DECIMAL(15,2) | NOT NULL |
| `currency` | CHAR(3) | DEFAULT 'USD' |
| `delivery_days` | SMALLINT | NOT NULL |
| `technical_notes` | TEXT | nullable |
| `status` | ENUM('draft','submitted','under_evaluation','won','lost','disqualified') | DEFAULT 'draft' |
| `submitted_at` | TIMESTAMP | nullable |
| `weighted_score` | DECIMAL(8,4) | nullable |
| `created_at` | TIMESTAMP | |
| `updated_at` | TIMESTAMP | |

**Unique:** `(tenant_id, tender_id, supplier_id)` | **Indexes:** `tenant_id`, `tender_id`, `supplier_id`, `status`

#### `bid_evaluation_criteria`
| Column | Type | Constraints |
|---|---|---|
| `id` | UUID | PK |
| `tenant_id` | UUID | FK → tenants(id) |
| `tender_id` | UUID | FK → tenders(id) |
| `name` | VARCHAR(255) | NOT NULL |
| `weight` | DECIMAL(5,2) | NOT NULL (0-100) |
| `max_score` | DECIMAL(5,2) | DEFAULT 100.00 |
| `created_at` | TIMESTAMP | |
| `updated_at` | TIMESTAMP | |

#### `bid_evaluations`
| Column | Type | Constraints |
|---|---|---|
| `id` | UUID | PK |
| `tenant_id` | UUID | FK → tenants(id) |
| `bid_id` | UUID | FK → bids(id) |
| `criteria_id` | UUID | FK → bid_evaluation_criteria(id) |
| `evaluator_id` | UUID | FK → users(id) |
| `score` | DECIMAL(5,2) | NOT NULL |
| `comment` | TEXT | nullable |
| `is_finalized` | BOOLEAN | DEFAULT false |
| `created_at` | TIMESTAMP | |
| `updated_at` | TIMESTAMP | |

**Unique:** `(tenant_id, bid_id, criteria_id, evaluator_id)`

#### `purchase_orders`
| Column | Type | Constraints |
|---|---|---|
| `id` | UUID | PK |
| `tenant_id` | UUID | FK → tenants(id) |
| `po_number` | VARCHAR(50) | NOT NULL |
| `purchase_request_id` | UUID | FK → purchase_requests(id), nullable |
| `bid_id` | UUID | FK → bids(id), nullable |
| `supplier_id` | UUID | FK → suppliers(id) |
| `department_id` | UUID | FK → departments(id) |
| `status` | ENUM('draft','issued','accepted','rejected','partially_received','fully_received','cancelled','overdue') | DEFAULT 'draft' |
| `total_amount` | DECIMAL(15,2) | NOT NULL |
| `currency` | CHAR(3) | DEFAULT 'USD' |
| `delivery_address` | TEXT | NOT NULL |
| `required_delivery_date` | DATE | NOT NULL |
| `issued_at` | TIMESTAMP | nullable |
| `accepted_at` | TIMESTAMP | nullable |
| `created_by` | UUID | FK → users(id) |
| `created_at` | TIMESTAMP | |
| `updated_at` | TIMESTAMP | |
| `deleted_at` | TIMESTAMP | nullable |

**Unique:** `(tenant_id, po_number)` | **Indexes:** `tenant_id`, `supplier_id`, `department_id`, `status`, `required_delivery_date`

#### `purchase_order_items`
| Column | Type | Constraints |
|---|---|---|
| `id` | UUID | PK |
| `tenant_id` | UUID | FK → tenants(id) |
| `purchase_order_id` | UUID | FK → purchase_orders(id) |
| `description` | VARCHAR(500) | NOT NULL |
| `quantity` | DECIMAL(15,2) | NOT NULL |
| `received_quantity` | DECIMAL(15,2) | DEFAULT 0.00 |
| `unit_of_measure` | VARCHAR(50) | NOT NULL |
| `unit_price` | DECIMAL(15,2) | NOT NULL |
| `total_price` | DECIMAL(15,2) | NOT NULL |
| `created_at` | TIMESTAMP | |
| `updated_at` | TIMESTAMP | |

#### `contracts`
| Column | Type | Constraints |
|---|---|---|
| `id` | UUID | PK |
| `tenant_id` | UUID | FK → tenants(id) |
| `contract_number` | VARCHAR(50) | NOT NULL |
| `purchase_order_id` | UUID | FK → purchase_orders(id), nullable |
| `tender_id` | UUID | FK → tenders(id), nullable |
| `supplier_id` | UUID | FK → suppliers(id) |
| `title` | VARCHAR(255) | NOT NULL |
| `scope` | TEXT | NOT NULL |
| `total_value` | DECIMAL(15,2) | NOT NULL |
| `consumed_value` | DECIMAL(15,2) | DEFAULT 0.00 |
| `currency` | CHAR(3) | DEFAULT 'USD' |
| `start_date` | DATE | NOT NULL |
| `end_date` | DATE | NOT NULL |
| `payment_terms` | TEXT | NOT NULL |
| `status` | ENUM('draft','pending_bond','active','expired','terminated','renewed') | DEFAULT 'draft' |
| `termination_reason` | TEXT | nullable |
| `created_by` | UUID | FK → users(id) |
| `created_at` | TIMESTAMP | |
| `updated_at` | TIMESTAMP | |
| `deleted_at` | TIMESTAMP | nullable |

**Unique:** `(tenant_id, contract_number)` | **Indexes:** `tenant_id`, `supplier_id`, `status`, `end_date`

#### `goods_receipts`
| Column | Type | Constraints |
|---|---|---|
| `id` | UUID | PK |
| `tenant_id` | UUID | FK → tenants(id) |
| `grn_number` | VARCHAR(50) | NOT NULL |
| `purchase_order_id` | UUID | FK → purchase_orders(id) |
| `warehouse_id` | UUID | FK → warehouses(id) |
| `delivery_note_number` | VARCHAR(100) | NOT NULL |
| `status` | ENUM('draft','under_inspection','accepted','partially_accepted','rejected') | DEFAULT 'draft' |
| `received_by` | UUID | FK → users(id) |
| `received_at` | TIMESTAMP | NOT NULL |
| `created_at` | TIMESTAMP | |
| `updated_at` | TIMESTAMP | |

#### `inventory`
| Column | Type | Constraints |
|---|---|---|
| `id` | UUID | PK |
| `tenant_id` | UUID | FK → tenants(id) |
| `warehouse_id` | UUID | FK → warehouses(id) |
| `item_code` | VARCHAR(100) | NOT NULL |
| `item_name` | VARCHAR(255) | NOT NULL |
| `category` | VARCHAR(100) | NOT NULL |
| `unit_of_measure` | VARCHAR(50) | NOT NULL |
| `current_stock` | DECIMAL(15,2) | DEFAULT 0.00 |
| `reorder_threshold` | DECIMAL(15,2) | DEFAULT 0.00 |
| `unit_cost` | DECIMAL(15,2) | DEFAULT 0.00 |
| `created_at` | TIMESTAMP | |
| `updated_at` | TIMESTAMP | |

**Unique:** `(tenant_id, warehouse_id, item_code)` | **Indexes:** `tenant_id`, `warehouse_id`, `category`

#### `invoices`
| Column | Type | Constraints |
|---|---|---|
| `id` | UUID | PK |
| `tenant_id` | UUID | FK → tenants(id) |
| `invoice_number` | VARCHAR(100) | NOT NULL |
| `supplier_id` | UUID | FK → suppliers(id) |
| `purchase_order_id` | UUID | FK → purchase_orders(id), nullable |
| `contract_id` | UUID | FK → contracts(id), nullable |
| `total_amount` | DECIMAL(15,2) | NOT NULL |
| `paid_amount` | DECIMAL(15,2) | DEFAULT 0.00 |
| `currency` | CHAR(3) | DEFAULT 'USD' |
| `invoice_date` | DATE | NOT NULL |
| `due_date` | DATE | NOT NULL |
| `status` | ENUM('submitted','under_review','approved','rejected','partially_paid','paid') | DEFAULT 'submitted' |
| `rejection_reason` | TEXT | nullable |
| `created_at` | TIMESTAMP | |
| `updated_at` | TIMESTAMP | |

**Indexes:** `tenant_id`, `supplier_id`, `purchase_order_id`, `status`, `due_date`

#### `payments`
| Column | Type | Constraints |
|---|---|---|
| `id` | UUID | PK |
| `tenant_id` | UUID | FK → tenants(id) |
| `invoice_id` | UUID | FK → invoices(id) |
| `amount` | DECIMAL(15,2) | NOT NULL |
| `payment_method` | VARCHAR(100) | NOT NULL |
| `payment_reference` | VARCHAR(255) | NOT NULL |
| `scheduled_date` | DATE | NOT NULL |
| `processed_at` | TIMESTAMP | nullable |
| `status` | ENUM('scheduled','processed','failed') | DEFAULT 'scheduled' |
| `processed_by` | UUID | FK → users(id) |
| `created_at` | TIMESTAMP | |
| `updated_at` | TIMESTAMP | |

#### `notifications`
| Column | Type | Constraints |
|---|---|---|
| `id` | UUID | PK |
| `tenant_id` | UUID | FK → tenants(id) |
| `user_id` | UUID | FK → users(id) |
| `event_type` | VARCHAR(100) | NOT NULL |
| `title` | VARCHAR(255) | NOT NULL |
| `message` | TEXT | NOT NULL |
| `data` | JSON | nullable (contextual payload) |
| `is_read` | BOOLEAN | DEFAULT false |
| `read_at` | TIMESTAMP | nullable |
| `created_at` | TIMESTAMP | |

**Indexes:** `tenant_id`, `user_id`, `is_read`, `event_type`, `created_at`

#### `audit_logs`
| Column | Type | Constraints |
|---|---|---|
| `id` | UUID | PK |
| `tenant_id` | UUID | nullable (null for system-level events) |
| `user_id` | UUID | nullable |
| `user_role` | VARCHAR(100) | nullable |
| `action_type` | VARCHAR(100) | NOT NULL |
| `entity_type` | VARCHAR(100) | NOT NULL |
| `entity_id` | UUID | nullable |
| `before_state` | JSON | nullable |
| `after_state` | JSON | nullable |
| `ip_address` | VARCHAR(45) | NOT NULL |
| `request_id` | VARCHAR(100) | nullable |
| `created_at` | TIMESTAMP | NOT NULL |

**Indexes:** `tenant_id`, `user_id`, `action_type`, `entity_type`, `entity_id`, `created_at`
**Note:** No `updated_at` or `deleted_at` — append-only by design.


---

## API Design

### Base URL and Versioning

All endpoints are served under `/api/v1`. Future breaking changes will be introduced under `/api/v2` without removing `/api/v1`.

### Standard Response Envelope

Every API response uses this consistent JSON structure:

```json
{
  "success": true,
  "data": { ... },
  "message": "Purchase request created successfully.",
  "errors": null,
  "meta": {
    "current_page": 1,
    "per_page": 20,
    "total": 150,
    "last_page": 8,
    "links": {
      "first": "/api/v1/purchase-requests?page=1",
      "last": "/api/v1/purchase-requests?page=8",
      "prev": null,
      "next": "/api/v1/purchase-requests?page=2"
    }
  }
}
```

On validation failure (HTTP 422):
```json
{
  "success": false,
  "data": null,
  "message": "Validation failed.",
  "errors": {
    "estimated_total": ["The estimated total must be greater than 0."],
    "department_id": ["The selected department is invalid."]
  },
  "meta": null
}
```

### HTTP Status Code Conventions

| Code | Meaning |
|---|---|
| 200 | Successful read / update |
| 201 | Successful create |
| 204 | Successful delete (no body) |
| 400 | Bad request (malformed syntax) |
| 401 | Unauthenticated (missing/invalid JWT) |
| 403 | Unauthorized (insufficient permissions) |
| 404 | Resource not found |
| 415 | Unsupported Media Type (non-JSON body) |
| 422 | Validation error |
| 429 | Rate limit exceeded |
| 500 | Internal server error |

### Authentication Endpoints

| Method | Path | Description | Auth |
|---|---|---|---|
| POST | `/api/v1/auth/login` | Issue JWT | Public |
| POST | `/api/v1/auth/logout` | Invalidate JWT | Required |
| POST | `/api/v1/auth/refresh` | Refresh JWT | Required |
| POST | `/api/v1/auth/password/request` | Request password reset | Public |
| POST | `/api/v1/auth/password/reset` | Reset password with token | Public |
| GET | `/api/v1/auth/me` | Get authenticated user profile | Required |

### Resource Endpoints

#### Tenant Management (System_Admin only)
| Method | Path | Description |
|---|---|---|
| GET | `/api/v1/tenants` | List all tenants |
| POST | `/api/v1/tenants` | Register new tenant |
| GET | `/api/v1/tenants/{id}` | Get tenant details |
| PATCH | `/api/v1/tenants/{id}` | Update tenant |
| PATCH | `/api/v1/tenants/{id}/status` | Change tenant status |

#### User Management
| Method | Path | Description |
|---|---|---|
| GET | `/api/v1/users` | List users (paginated, filterable) |
| POST | `/api/v1/users` | Create user |
| GET | `/api/v1/users/{id}` | Get user |
| PATCH | `/api/v1/users/{id}` | Update user |
| DELETE | `/api/v1/users/{id}` | Deactivate user |
| POST | `/api/v1/users/{id}/roles` | Assign role |
| DELETE | `/api/v1/users/{id}/roles/{role}` | Revoke role |

#### Departments
| Method | Path | Description |
|---|---|---|
| GET | `/api/v1/departments` | List departments |
| POST | `/api/v1/departments` | Create department |
| GET | `/api/v1/departments/{id}` | Get department |
| PATCH | `/api/v1/departments/{id}` | Update department |
| PATCH | `/api/v1/departments/{id}/status` | Activate/deactivate |

#### Budgets
| Method | Path | Description |
|---|---|---|
| GET | `/api/v1/budgets` | List budgets |
| POST | `/api/v1/budgets` | Create budget allocation |
| GET | `/api/v1/budgets/{id}` | Get budget with utilization |
| POST | `/api/v1/budgets/transfer` | Transfer between departments |
| GET | `/api/v1/budgets/report` | Budget utilization report |

#### Purchase Requests
| Method | Path | Description |
|---|---|---|
| GET | `/api/v1/purchase-requests` | List PRs (filterable by status, dept, date) |
| POST | `/api/v1/purchase-requests` | Create PR (draft) |
| GET | `/api/v1/purchase-requests/{id}` | Get PR with items and history |
| PATCH | `/api/v1/purchase-requests/{id}` | Update PR (draft only) |
| POST | `/api/v1/purchase-requests/{id}/submit` | Submit for approval |
| POST | `/api/v1/purchase-requests/{id}/cancel` | Cancel PR |
| POST | `/api/v1/purchase-requests/{id}/attachments` | Upload attachment |

#### Approvals
| Method | Path | Description |
|---|---|---|
| GET | `/api/v1/approvals/pending` | List pending approvals for current user |
| POST | `/api/v1/approvals/{id}/approve` | Approve document |
| POST | `/api/v1/approvals/{id}/reject` | Reject document |
| POST | `/api/v1/approvals/{id}/return` | Return for revision |
| GET | `/api/v1/approval-workflows` | List configured workflows |
| POST | `/api/v1/approval-workflows` | Create workflow |
| PATCH | `/api/v1/approval-workflows/{id}` | Update workflow |

#### Suppliers
| Method | Path | Description |
|---|---|---|
| GET | `/api/v1/suppliers` | List suppliers |
| POST | `/api/v1/suppliers/register` | Self-register (public) |
| GET | `/api/v1/suppliers/{id}` | Get supplier profile |
| PATCH | `/api/v1/suppliers/{id}` | Update supplier |
| POST | `/api/v1/suppliers/{id}/approve` | Approve registration |
| POST | `/api/v1/suppliers/{id}/blacklist` | Blacklist supplier |
| GET | `/api/v1/suppliers/{id}/performance` | Get performance metrics |
| POST | `/api/v1/suppliers/{id}/documents` | Upload compliance document |

#### Tenders
| Method | Path | Description |
|---|---|---|
| GET | `/api/v1/tenders` | List tenders |
| POST | `/api/v1/tenders` | Create tender |
| GET | `/api/v1/tenders/{id}` | Get tender details |
| PATCH | `/api/v1/tenders/{id}` | Update tender (draft only) |
| POST | `/api/v1/tenders/{id}/publish` | Publish tender |
| POST | `/api/v1/tenders/{id}/cancel` | Cancel tender |
| PATCH | `/api/v1/tenders/{id}/deadline` | Extend deadline |
| POST | `/api/v1/tenders/{id}/documents` | Upload tender document |

#### Bids
| Method | Path | Description |
|---|---|---|
| GET | `/api/v1/tenders/{tenderId}/bids` | List bids (Procurement_Officer) |
| POST | `/api/v1/tenders/{tenderId}/bids` | Submit bid (Supplier) |
| GET | `/api/v1/tenders/{tenderId}/bids/{id}` | Get bid |
| PATCH | `/api/v1/tenders/{tenderId}/bids/{id}` | Revise bid (before deadline) |
| POST | `/api/v1/tenders/{tenderId}/bids/{id}/documents` | Upload bid document |

#### Bid Evaluation
| Method | Path | Description |
|---|---|---|
| GET | `/api/v1/tenders/{tenderId}/evaluation/criteria` | Get evaluation criteria |
| POST | `/api/v1/tenders/{tenderId}/evaluation/criteria` | Define criteria |
| POST | `/api/v1/tenders/{tenderId}/evaluation/scores` | Submit scores |
| GET | `/api/v1/tenders/{tenderId}/evaluation/report` | Get ranked report |
| POST | `/api/v1/tenders/{tenderId}/evaluation/winner` | Select winner |

#### Purchase Orders
| Method | Path | Description |
|---|---|---|
| GET | `/api/v1/purchase-orders` | List POs |
| POST | `/api/v1/purchase-orders` | Create PO |
| GET | `/api/v1/purchase-orders/{id}` | Get PO |
| PATCH | `/api/v1/purchase-orders/{id}` | Amend PO |
| POST | `/api/v1/purchase-orders/{id}/issue` | Issue to supplier |
| POST | `/api/v1/purchase-orders/{id}/accept` | Supplier accepts |
| POST | `/api/v1/purchase-orders/{id}/reject` | Supplier rejects |
| POST | `/api/v1/purchase-orders/{id}/cancel` | Cancel PO |

#### Contracts
| Method | Path | Description |
|---|---|---|
| GET | `/api/v1/contracts` | List contracts |
| POST | `/api/v1/contracts` | Create contract |
| GET | `/api/v1/contracts/{id}` | Get contract |
| POST | `/api/v1/contracts/{id}/activate` | Activate (requires bond) |
| POST | `/api/v1/contracts/{id}/amend` | Create amendment |
| POST | `/api/v1/contracts/{id}/terminate` | Terminate contract |
| POST | `/api/v1/contracts/{id}/documents` | Upload document |

#### Goods Receipts & Inventory
| Method | Path | Description |
|---|---|---|
| GET | `/api/v1/goods-receipts` | List GRNs |
| POST | `/api/v1/goods-receipts` | Create GRN |
| GET | `/api/v1/goods-receipts/{id}` | Get GRN |
| POST | `/api/v1/goods-receipts/{id}/inspect` | Submit inspection result |
| GET | `/api/v1/inventory` | List inventory |
| GET | `/api/v1/inventory/{id}` | Get item stock |
| GET | `/api/v1/warehouses` | List warehouses |

#### Invoices & Payments
| Method | Path | Description |
|---|---|---|
| GET | `/api/v1/invoices` | List invoices |
| POST | `/api/v1/invoices` | Submit invoice (Supplier) |
| GET | `/api/v1/invoices/{id}` | Get invoice |
| POST | `/api/v1/invoices/{id}/approve` | Approve invoice |
| POST | `/api/v1/invoices/{id}/reject` | Reject invoice |
| POST | `/api/v1/payments` | Record payment |
| GET | `/api/v1/payments` | List payments |
| GET | `/api/v1/payments/schedule` | Payment schedule report |

#### Notifications
| Method | Path | Description |
|---|---|---|
| GET | `/api/v1/notifications` | List notifications (paginated) |
| PATCH | `/api/v1/notifications/{id}/read` | Mark as read |
| POST | `/api/v1/notifications/read-all` | Mark all as read |
| GET | `/api/v1/notifications/unread-count` | Get unread count |

#### Reports
| Method | Path | Description |
|---|---|---|
| GET | `/api/v1/reports/dashboard` | Role-specific KPI dashboard |
| GET | `/api/v1/reports/procurement-timeline` | Cycle time report |
| GET | `/api/v1/reports/spending-analytics` | Spending by dept/category/supplier |
| GET | `/api/v1/reports/supplier-performance` | Supplier performance report |
| GET | `/api/v1/reports/tender-statistics` | Tender stats report |
| GET | `/api/v1/reports/financial-summary` | Financial summary report |
| POST | `/api/v1/reports/export` | Request report export (PDF/Excel) |

#### Audit Logs
| Method | Path | Description |
|---|---|---|
| GET | `/api/v1/audit-logs` | Search audit logs (filterable) |

#### System
| Method | Path | Description |
|---|---|---|
| GET | `/api/health` | Health check (DB, Redis, Queue) |
| GET | `/api/documentation` | Swagger UI |

### Query Parameters (List Endpoints)

All list endpoints support:
- `page` (integer, default: 1)
- `per_page` (integer, default: 20, max: 100)
- `sort_by` (field name)
- `sort_dir` (asc|desc, default: desc)
- `search` (full-text search string)
- `status` (filter by status)
- `department_id` (filter by department)
- `date_from` / `date_to` (date range filter)
- `include` (comma-separated related resources to embed)


---

## Frontend Architecture (Next.js 15)

### App Router Directory Structure

```
src/
├── app/
│   ├── (auth)/
│   │   ├── login/page.tsx
│   │   └── reset-password/page.tsx
│   ├── (dashboard)/
│   │   ├── layout.tsx                    # Role-based sidebar + header
│   │   ├── page.tsx                      # Role-specific dashboard redirect
│   │   ├── purchase-requests/
│   │   │   ├── page.tsx                  # List view
│   │   │   ├── new/page.tsx              # Create form
│   │   │   └── [id]/page.tsx             # Detail view
│   │   ├── approvals/
│   │   │   └── page.tsx
│   │   ├── suppliers/
│   │   │   ├── page.tsx
│   │   │   └── [id]/page.tsx
│   │   ├── tenders/
│   │   │   ├── page.tsx
│   │   │   ├── new/page.tsx
│   │   │   └── [id]/
│   │   │       ├── page.tsx
│   │   │       └── evaluation/page.tsx
│   │   ├── purchase-orders/
│   │   ├── contracts/
│   │   ├── goods-receipts/
│   │   ├── inventory/
│   │   ├── invoices/
│   │   ├── payments/
│   │   ├── budgets/
│   │   ├── reports/
│   │   ├── notifications/
│   │   ├── audit-logs/
│   │   ├── users/
│   │   └── settings/
│   ├── globals.css
│   └── layout.tsx                        # Root layout (theme provider)
├── components/
│   ├── ui/                               # ShadCN UI components
│   ├── layout/
│   │   ├── Sidebar.tsx
│   │   ├── Header.tsx
│   │   └── NotificationBell.tsx
│   ├── forms/
│   │   ├── PurchaseRequestForm.tsx
│   │   ├── TenderForm.tsx
│   │   └── ...
│   ├── tables/
│   │   ├── DataTable.tsx                 # Generic paginated table
│   │   └── ...
│   ├── charts/
│   │   ├── SpendingChart.tsx             # Recharts + Framer Motion
│   │   ├── BudgetUtilizationChart.tsx
│   │   └── ...
│   └── shared/
│       ├── LoadingSkeleton.tsx
│       ├── ErrorBoundary.tsx
│       └── StatusBadge.tsx
├── hooks/
│   ├── useAuth.ts
│   ├── useTenant.ts
│   └── useNotifications.ts
├── lib/
│   ├── api/
│   │   ├── client.ts                     # Axios instance with interceptors
│   │   └── endpoints/
│   │       ├── purchaseRequests.ts
│   │       └── ...
│   ├── validations/
│   │   ├── purchaseRequest.schema.ts     # Zod schemas
│   │   └── ...
│   └── utils.ts
├── providers/
│   ├── QueryProvider.tsx                 # TanStack Query client
│   ├── ThemeProvider.tsx                 # Dark/light mode
│   └── AuthProvider.tsx
├── store/
│   ├── authStore.ts                      # Zustand: user, token, tenant
│   ├── notificationStore.ts             # Zustand: unread count, list
│   └── uiStore.ts                        # Zustand: sidebar state, theme
└── types/
    ├── api.types.ts                      # API response types
    ├── models.types.ts                   # Entity types
    └── ...
```

### Role-Based Routing

Route access is enforced at two levels:

1. **Middleware** (`middleware.ts`): Validates JWT on every request, redirects unauthenticated users to `/login`.
2. **Layout-level guard** (`(dashboard)/layout.tsx`): Reads the user's role from Zustand store and renders only permitted sidebar items.

```typescript
// Role → permitted modules mapping
const ROLE_MODULES: Record<string, string[]> = {
  Tenant_Admin: ['dashboard', 'users', 'departments', 'budgets', 'reports', 'audit-logs', 'settings'],
  Department_Staff: ['dashboard', 'purchase-requests', 'notifications'],
  Procurement_Officer: ['dashboard', 'purchase-requests', 'suppliers', 'tenders', 'purchase-orders', 'contracts', 'notifications'],
  Finance_Officer: ['dashboard', 'budgets', 'invoices', 'payments', 'reports', 'notifications'],
  Store_Manager: ['dashboard', 'goods-receipts', 'inventory', 'notifications'],
  Committee_Member: ['dashboard', 'tenders', 'goods-receipts', 'notifications'],
  Supplier: ['dashboard', 'tenders', 'bids', 'purchase-orders', 'invoices', 'notifications'],
};
```

### Zustand Store Structure

```typescript
// authStore.ts
interface AuthState {
  user: User | null;
  token: string | null;
  tenant: Tenant | null;
  role: string | null;
  isAuthenticated: boolean;
  login: (credentials: LoginCredentials) => Promise<void>;
  logout: () => void;
  refreshToken: () => Promise<void>;
}

// notificationStore.ts
interface NotificationState {
  unreadCount: number;
  notifications: Notification[];
  setUnreadCount: (count: number) => void;
  markAsRead: (id: string) => void;
  markAllAsRead: () => void;
}
```

### TanStack Query Configuration

```typescript
// providers/QueryProvider.tsx
const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 30_000,        // 30 seconds
      gcTime: 5 * 60_000,       // 5 minutes
      retry: 2,
      refetchOnWindowFocus: false,
    },
    mutations: {
      onError: (error) => toast.error(getErrorMessage(error)),
    },
  },
});
```

Optimistic updates are used for status-change actions (approve, reject, mark-as-read) to provide immediate visual feedback before the server confirms.

### Form Handling

All forms use React Hook Form with Zod schema validation:

```typescript
// Example: PurchaseRequestForm
const prSchema = z.object({
  title: z.string().min(3).max(255),
  department_id: z.string().uuid(),
  required_date: z.string().datetime().optional(),
  items: z.array(z.object({
    description: z.string().min(1).max(500),
    quantity: z.number().positive(),
    unit_of_measure: z.string().min(1),
    estimated_unit_price: z.number().positive(),
    budget_code: z.string().optional(),
  })).min(1),
});
```

### Dark/Light Mode

Theme preference is stored in `localStorage` under `pmp-theme` and applied via a `data-theme` attribute on the root `<html>` element. ShadCN UI's CSS variables handle the color switching. The `ThemeProvider` reads the preference on mount and syncs with the Zustand `uiStore`.

---

## Mobile Architecture (Flutter)

### Clean Architecture Layers

```
lib/
├── core/
│   ├── constants/
│   ├── errors/
│   │   ├── failures.dart
│   │   └── exceptions.dart
│   ├── network/
│   │   ├── api_client.dart               # Dio HTTP client
│   │   └── interceptors/
│   │       ├── auth_interceptor.dart
│   │       └── tenant_interceptor.dart
│   └── utils/
├── features/
│   ├── auth/
│   │   ├── data/
│   │   │   ├── datasources/auth_remote_datasource.dart
│   │   │   ├── models/user_model.dart
│   │   │   └── repositories/auth_repository_impl.dart
│   │   ├── domain/
│   │   │   ├── entities/user.dart
│   │   │   ├── repositories/auth_repository.dart
│   │   │   └── usecases/login_usecase.dart
│   │   └── presentation/
│   │       ├── providers/auth_provider.dart
│   │       └── screens/login_screen.dart
│   ├── dashboard/
│   ├── tenders/
│   ├── bids/
│   ├── purchase_orders/
│   ├── invoices/
│   └── notifications/
└── main.dart
```

### State Management (Riverpod)

```dart
// Auth state provider
final authProvider = StateNotifierProvider<AuthNotifier, AuthState>((ref) {
  return AuthNotifier(ref.read(authRepositoryProvider));
});

// Tender list provider with pagination
final tenderListProvider = FutureProvider.family<PaginatedResult<Tender>, TenderFilters>(
  (ref, filters) => ref.read(tenderRepositoryProvider).getTenders(filters),
);
```

### Offline Support Strategy

The mobile app targets the Supplier portal use case. Offline support is implemented via:

1. **Hive** local database for caching last-fetched dashboard data, tender list, and PO list.
2. **Connectivity Plus** package to detect network state.
3. When offline, the app displays cached data with a prominent "Offline - showing cached data" banner.
4. Write operations (bid submission, invoice upload) are queued locally and synced when connectivity is restored.
5. Cache TTL: 24 hours for list data, 1 hour for detail data.

### API Service Layer

```dart
// core/network/api_client.dart
class ApiClient {
  final Dio _dio;

  ApiClient() {
    _dio = Dio(BaseOptions(
      baseUrl: AppConstants.apiBaseUrl,
      connectTimeout: const Duration(seconds: 10),
      receiveTimeout: const Duration(seconds: 30),
      headers: {'Accept': 'application/json', 'Content-Type': 'application/json'},
    ));
    _dio.interceptors.addAll([
      AuthInterceptor(),
      TenantInterceptor(),
      LogInterceptor(),
    ]);
  }
}
```


---

## Security Design

### JWT Token Structure

```json
{
  "header": { "alg": "HS256", "typ": "JWT" },
  "payload": {
    "sub": "user-uuid-here",
    "user_id": "user-uuid-here",
    "tenant_id": "tenant-uuid-here",
    "role": "Procurement_Officer",
    "permissions": ["create-purchase-request", "manage-tenders"],
    "iat": 1700000000,
    "exp": 1700086400,
    "jti": "unique-token-id"
  }
}
```

JWT secret is stored in `JWT_SECRET` environment variable (minimum 64 characters). Tokens are invalidated on logout by storing the `jti` in a Redis blacklist with TTL matching the token's remaining lifetime.

### RBAC Permission Matrix

| Permission | System_Admin | Tenant_Admin | Dept_Staff | Proc_Officer | Finance_Officer | Store_Manager | Committee_Member | Supplier |
|---|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|
| manage-tenants | ✓ | | | | | | | |
| manage-users | | ✓ | | | | | | |
| manage-departments | | ✓ | | | | | | |
| create-purchase-request | | | ✓ | | | | | |
| approve-purchase-request | | ✓ | | ✓ | ✓ | | | |
| manage-suppliers | | | | ✓ | | | | |
| manage-tenders | | | | ✓ | | | | |
| submit-bid | | | | | | | | ✓ |
| evaluate-bids | | | | ✓ | | | ✓ | |
| manage-purchase-orders | | | | ✓ | | | | |
| accept-purchase-order | | | | | | | | ✓ |
| manage-contracts | | | | ✓ | | | | |
| manage-goods-receipts | | | | | | ✓ | | |
| inspect-goods | | | | | | | ✓ | |
| manage-budgets | | | | | ✓ | | | |
| manage-invoices | | | | | ✓ | | | ✓ |
| process-payments | | | | | ✓ | | | |
| view-reports | | ✓ | | ✓ | ✓ | ✓ | | |
| view-audit-logs | ✓ | ✓ | | | | | | |
| manage-notifications | | ✓ | | | | | | |

### Tenant Isolation Enforcement

Tenant isolation is enforced at three independent layers:

1. **Middleware Layer**: `TenantIdentificationMiddleware` sets the active tenant in the application container on every request.
2. **Model Layer**: A `TenantScope` global scope is applied to all tenant-scoped Eloquent models, automatically appending `WHERE tenant_id = ?` to every query.
3. **Repository Layer**: All repository methods call `$this->query()` which includes the tenant scope, making it impossible to accidentally query without tenant scoping.

```php
// Trait applied to all tenant-scoped models
trait HasTenantScope {
    protected static function booted(): void {
        static::addGlobalScope('tenant', function (Builder $builder) {
            if (app()->has('tenant')) {
                $builder->where('tenant_id', app('tenant')->id);
            }
        });

        static::creating(function ($model) {
            if (app()->has('tenant') && empty($model->tenant_id)) {
                $model->tenant_id = app('tenant')->id;
            }
        });
    }
}
```

### Rate Limiting Configuration

```php
// routes/api.php
RateLimiter::for('auth', function (Request $request) {
    return Limit::perMinute(60)->by($request->ip());
});

RateLimiter::for('api', function (Request $request) {
    return Limit::perMinute(300)->by($request->user()?->id ?: $request->ip());
});
```

### File Upload Security

```php
// FileManagementService.php
class FileManagementService {
    private const ALLOWED_MIMES = ['application/pdf', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'image/png', 'image/jpeg'];
    private const MAX_SIZE_BYTES = 10 * 1024 * 1024; // 10 MB

    public function upload(UploadedFile $file, string $entityType, string $entityId): FileRecord {
        // 1. Validate MIME type against allowed list
        // 2. Validate file size
        // 3. Generate non-guessable storage key (UUID + hash)
        // 4. Store in tenant-scoped path: {tenant_id}/{entity_type}/{uuid}.{ext}
        // 5. Dispatch virus scan job (async)
        // 6. Return file record
    }
}
```

Storage keys use `Str::uuid() . '-' . hash('sha256', $file->getClientOriginalName() . time())` to prevent enumeration.

### CSRF Protection

Browser-based clients receive a CSRF token via the `/api/v1/auth/csrf-token` endpoint. All state-changing requests from browser clients must include the `X-CSRF-TOKEN` header. API clients using JWT (mobile, third-party) are exempt from CSRF checks via the `api` middleware group.

---

## Real-time Notifications

### WebSocket Architecture

```
Laravel App → Event Broadcasting → Soketi (self-hosted Pusher-compatible)
                                        │
                                        ▼
                              Next.js (Laravel Echo client)
                              Flutter (Pusher Dart client)
```

**Soketi** is used as the self-hosted WebSocket server (Pusher-compatible protocol), avoiding external service dependency. In production, this can be swapped for Pusher by changing environment variables.

### Broadcasting Configuration

```php
// config/broadcasting.php
'pusher' => [
    'driver' => 'pusher',
    'key' => env('PUSHER_APP_KEY'),
    'secret' => env('PUSHER_APP_SECRET'),
    'app_id' => env('PUSHER_APP_ID'),
    'options' => [
        'host' => env('PUSHER_HOST', 'soketi'),
        'port' => env('PUSHER_PORT', 6001),
        'scheme' => 'http',
        'encrypted' => true,
        'useTLS' => false,
    ],
],
```

### Channel Design

All channels are **private** and scoped to a tenant:

| Channel | Format | Subscribers |
|---|---|---|
| User notifications | `private-tenant.{tenantId}.user.{userId}` | Individual user |
| Tenant-wide events | `private-tenant.{tenantId}` | All tenant users |
| Approval queue | `private-tenant.{tenantId}.approvals` | Approvers |

Channel authorization is handled by `routes/channels.php`:

```php
Broadcast::channel('tenant.{tenantId}.user.{userId}', function ($user, $tenantId, $userId) {
    return $user->tenant_id === $tenantId && $user->id === $userId;
});
```

### Notification Events and Triggers

| Event | Trigger | Recipients |
|---|---|---|
| `PurchaseRequestSubmitted` | PR submitted for approval | Designated approvers |
| `PurchaseRequestStatusChanged` | PR approved/rejected/returned | PR submitter |
| `TenderPublished` | Tender published | All active suppliers in category |
| `BidDeadlineApproaching` | 24h before tender deadline | Suppliers who haven't bid |
| `BidEvaluationCompleted` | Winner selected | All bidding suppliers |
| `PurchaseOrderIssued` | PO issued | Supplier |
| `PurchaseOrderStatusChanged` | PO accepted/rejected | Procurement_Officer |
| `GoodsReceiptCreated` | GRN created | Procurement_Officer, Supplier |
| `InvoiceSubmitted` | Invoice submitted | Finance_Officer |
| `InvoiceStatusChanged` | Invoice approved/rejected | Supplier |
| `PaymentProcessed` | Payment recorded | Supplier |
| `BudgetThresholdReached` | 75% or 90% budget consumed | Finance_Officer, Tenant_Admin |
| `ContractRenewalAlert` | 60 or 30 days before expiry | Procurement_Officer, Tenant_Admin |
| `AccountLocked` | Failed login threshold reached | Affected user |
| `LowStockAlert` | Stock below reorder threshold | Store_Manager, Procurement_Officer |

### Queue-Based Email Notifications

Email notifications are dispatched as queued jobs to the `notifications` queue:

```php
// Listener: SendPurchaseRequestNotification
public function handle(PurchaseRequestSubmitted $event): void {
    foreach ($event->approvers as $approver) {
        SendNotificationEmailJob::dispatch($approver, $event->purchaseRequest)
            ->onQueue('notifications');
    }
}
```

Failed email jobs are retried 3 times with exponential backoff. After 3 failures, the job is moved to the failed jobs table and a system alert is sent to the System_Admin.

---

## Infrastructure & DevOps

### Docker Compose Services

```yaml
# docker-compose.yml (production)
services:
  app:
    build: ./backend
    environment:
      - APP_ENV=production
    depends_on: [mysql, redis]
    networks: [pmp-network]

  frontend:
    build: ./frontend
    environment:
      - NEXT_PUBLIC_API_URL=https://api.platform.com
    networks: [pmp-network]

  nginx:
    image: nginx:alpine
    ports: ["80:80", "443:443"]
    volumes:
      - ./nginx/conf.d:/etc/nginx/conf.d
      - ./nginx/ssl:/etc/nginx/ssl
    depends_on: [app, frontend]
    networks: [pmp-network]

  mysql:
    image: mysql:8.0
    environment:
      MYSQL_ROOT_PASSWORD: ${DB_ROOT_PASSWORD}
      MYSQL_DATABASE: ${DB_DATABASE}
    volumes:
      - mysql-data:/var/lib/mysql
    networks: [pmp-network]

  redis:
    image: redis:7-alpine
    command: redis-server --requirepass ${REDIS_PASSWORD}
    volumes:
      - redis-data:/data
    networks: [pmp-network]

  queue-worker:
    build: ./backend
    command: php artisan queue:work redis --queue=notifications,default,reports --tries=3 --backoff=60
    depends_on: [app, redis]
    networks: [pmp-network]

  scheduler:
    build: ./backend
    command: php artisan schedule:work
    depends_on: [app, mysql, redis]
    networks: [pmp-network]

  soketi:
    image: quay.io/soketi/soketi:latest
    environment:
      SOKETI_DEFAULT_APP_ID: ${PUSHER_APP_ID}
      SOKETI_DEFAULT_APP_KEY: ${PUSHER_APP_KEY}
      SOKETI_DEFAULT_APP_SECRET: ${PUSHER_APP_SECRET}
    networks: [pmp-network]

volumes:
  mysql-data:
  redis-data:

networks:
  pmp-network:
    driver: bridge
```

### Nginx Configuration

```nginx
server {
    listen 443 ssl http2;
    server_name *.platform.com;

    ssl_certificate /etc/nginx/ssl/cert.pem;
    ssl_certificate_key /etc/nginx/ssl/key.pem;

    # Security headers
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Content-Security-Policy "default-src 'self'; script-src 'self' 'unsafe-inline';" always;

    location /api/ {
        proxy_pass http://app:9000;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header Host $host;
    }

    location / {
        proxy_pass http://frontend:3000;
    }
}

server {
    listen 80;
    return 301 https://$host$request_uri;
}
```

### CI/CD Pipeline (GitHub Actions)

```yaml
# .github/workflows/ci.yml
name: CI/CD Pipeline

on:
  push:
    branches: [main]
  pull_request:
    branches: [main]

jobs:
  test-backend:
    runs-on: ubuntu-latest
    services:
      mysql:
        image: mysql:8.0
        env: { MYSQL_ROOT_PASSWORD: secret, MYSQL_DATABASE: pmp_test }
      redis:
        image: redis:7-alpine
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.3', extensions: 'pdo_mysql, redis' }
      - run: composer install --no-dev
      - run: php artisan test --parallel
        working-directory: ./backend

  test-frontend:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with: { node-version: '20' }
      - run: npm ci && npm run test:ci
        working-directory: ./frontend

  build-and-deploy:
    needs: [test-backend, test-frontend]
    if: github.ref == 'refs/heads/main'
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - name: Build Docker images
        run: docker compose -f docker-compose.prod.yml build
      - name: Push to registry
        run: docker compose -f docker-compose.prod.yml push
      - name: Deploy to production
        run: |
          ssh deploy@${{ secrets.PROD_HOST }} 'cd /app && docker compose pull && docker compose up -d'
```

### Health Check Endpoint

```php
// GET /api/health
public function health(): JsonResponse {
    $checks = [
        'database' => $this->checkDatabase(),
        'redis' => $this->checkRedis(),
        'queue' => $this->checkQueueWorker(),
    ];

    $allHealthy = collect($checks)->every(fn($c) => $c['status'] === 'ok');

    return response()->json([
        'status' => $allHealthy ? 'healthy' : 'degraded',
        'timestamp' => now()->toIso8601String(),
        'checks' => $checks,
    ], $allHealthy ? 200 : 503);
}
```

### Environment Variables

All sensitive configuration is managed via environment variables. Key variables:

```env
# Application
APP_KEY=base64:...
APP_ENV=production
JWT_SECRET=<64-char-minimum-random-string>

# Database
DB_HOST=mysql
DB_DATABASE=pmp_production
DB_USERNAME=pmp_user
DB_PASSWORD=<strong-password>

# Redis
REDIS_HOST=redis
REDIS_PASSWORD=<strong-password>

# Mail
MAIL_MAILER=smtp
MAIL_HOST=<smtp-host>
MAIL_USERNAME=<smtp-user>
MAIL_PASSWORD=<smtp-password>

# WebSockets
PUSHER_APP_ID=<app-id>
PUSHER_APP_KEY=<app-key>
PUSHER_APP_SECRET=<app-secret>
PUSHER_HOST=soketi

# File Storage
FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=<key>
AWS_SECRET_ACCESS_KEY=<secret>
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=pmp-files
```


---

## Correctness Properties

*A property is a characteristic or behavior that should hold true across all valid executions of a system — essentially, a formal statement about what the system should do. Properties serve as the bridge between human-readable specifications and machine-verifiable correctness guarantees.*

The following properties were derived from the acceptance criteria in the requirements document. Each property is universally quantified and suitable for property-based testing using [Pest PHP](https://pestphp.com/) with the [pest-plugin-faker](https://github.com/pestphp/pest-plugin-faker) or a dedicated PBT library.

---

### Property 1: Tenant Data Isolation

*For any* two distinct tenants A and B, and for any data record belonging to tenant A, executing any read, list, or search query in the context of tenant B SHALL never return that record.

This property must hold across all entity types: users, departments, budgets, purchase requests, suppliers, tenders, bids, purchase orders, contracts, goods receipts, invoices, payments, notifications, and audit logs.

**Validates: Requirements 1.2, 1.4, Business Rule 1 (Tenant Scoping), Business Rule 10 (Cross-Tenant Data Isolation)**

---

### Property 2: Document Number Uniqueness and Format

*For any* sequence of N purchase request creations within a single tenant and fiscal year (where N is arbitrarily large), all generated PR numbers SHALL be unique and SHALL match the format `PR-{TENANT_CODE}-{YEAR}-{SEQUENCE}` where SEQUENCE is a zero-padded monotonically increasing integer.

The same property applies to Purchase Order numbers with format `PO-{TENANT_CODE}-{YEAR}-{SEQUENCE}`.

Additionally, *for any* two tenants generating document numbers concurrently, the numbers generated by tenant A SHALL never collide with numbers generated by tenant B.

**Validates: Requirements 5.1, 10.1, 21.8**

---

### Property 3: Budget Enforcement Invariant

*For any* department with a budget allocation B and current available balance V (where V ≤ B), and for any purchase request with estimated total T:
- If T > V, the PR submission SHALL be rejected with an error containing the available balance and shortfall amount.
- If T ≤ V, the PR submission SHALL succeed (subject to other validations).

This invariant must hold regardless of concurrent submissions, race conditions, or the number of existing PRs in the system.

**Validates: Requirements 5.3, 5.4, 13.2, 13.3, Business Rule 2 (Budget Enforcement)**

---

### Property 4: Budget Encumbrance Round-Trip

*For any* department budget with available balance V, and for any purchase order with value P (where P ≤ V):
- After the PO is issued, the available balance SHALL equal V - P (encumbrance applied).
- After the PO is subsequently cancelled or rejected, the available balance SHALL equal V (encumbrance released).

The round-trip property guarantees that issuing then cancelling a PO leaves the budget in its original state.

**Validates: Requirements 13.4, 13.5**

---

### Property 5: JWT Claims Completeness

*For any* valid user belonging to any tenant with any role, the JWT issued upon successful authentication SHALL contain all of the following claims: `user_id` (UUID v4), `tenant_id` (UUID v4), `role` (valid role name), `exp` (future timestamp), `iat` (past timestamp), and `jti` (unique token identifier).

No issued JWT SHALL contain a `tenant_id` that differs from the authenticated user's actual tenant.

**Validates: Requirements 2.1, 1.1**

---

### Property 6: Bid Evaluation Weighted Score Calculation

*For any* bid with any set of evaluation scores S₁, S₂, ..., Sₙ and corresponding criteria weights W₁, W₂, ..., Wₙ (where all weights are positive and sum to 100), the calculated weighted total score SHALL equal exactly Σ(Sᵢ × Wᵢ / 100) for i = 1 to n, with no floating-point precision loss (computed using DECIMAL arithmetic).

**Validates: Requirements 9.3**

---

### Property 7: Evaluation Criteria Weights Sum to 100

*For any* set of bid evaluation criteria defined for a tender, the sum of all criterion weights SHALL equal exactly 100.00. The system SHALL reject any criteria configuration where the weights do not sum to 100, returning a validation error.

**Validates: Requirements 9.1**

---

### Property 8: Approval Workflow State Machine Progression

*For any* document (PR, Tender, PO, Contract, Invoice) submitted through an approval workflow with L levels (1 ≤ L ≤ 10):
- After approval at level k (where k < L), the document status SHALL be `pending_approval` and the active level SHALL be k+1.
- After approval at level L (the final level), the document status SHALL be `approved`.
- After rejection at any level k, the document status SHALL be `rejected` regardless of remaining levels.
- After a return-for-revision action at any level k, the document status SHALL be `revision_required`.

No document SHALL skip levels or transition to `approved` before all L levels have been completed.

**Validates: Requirements 6.3, 6.4, 6.5**

---

### Property 9: Bid Deadline Enforcement

*For any* tender with submission deadline D, and for any bid submission attempt at timestamp T:
- If T < D, the bid submission SHALL succeed (subject to other validations).
- If T ≥ D, the bid submission SHALL be rejected with an appropriate error message.

This property must hold regardless of timezone, clock skew between client and server, or the number of existing bids on the tender.

**Validates: Requirements 8.4, 8.6, Business Rule 3 (Bid Immutability After Deadline)**

---

### Property 10: Audit Log Immutability

*For any* audit log record created at any point in time, no subsequent API request (regardless of the requesting user's role, including System_Admin) SHALL be able to modify or delete that record. Any attempt to update or delete an audit log record SHALL be rejected with HTTP 403.

The total count of audit log records SHALL be monotonically non-decreasing — records are only ever added, never removed.

**Validates: Requirements 17.5, 17.6, Business Rule 6 (Audit Log Immutability)**

---

### Property 11: API Response Envelope Consistency

*For any* successful API request to any endpoint, the response SHALL contain a JSON object with the following fields present: `success` (boolean, true), `data` (object or array, non-null), `message` (string), `errors` (null), and `meta` (object with pagination fields for list endpoints, null for single-resource endpoints).

*For any* failed API request (validation error), the response SHALL contain: `success` (boolean, false), `data` (null), `message` (string), `errors` (object mapping field names to error arrays), and `meta` (null).

**Validates: Requirements 18.2, 18.3**

---

### Property 12: JSON Serialization Round-Trip

*For any* valid API resource object of any entity type (User, PurchaseRequest, Supplier, Tender, Bid, PurchaseOrder, Contract, Invoice, Payment, etc.), serializing the object to a JSON API response and then parsing that JSON response back into a resource object SHALL produce an object with identical field values.

Specifically:
- All UUID fields SHALL survive the round-trip as valid UUID v4 strings.
- All monetary values SHALL survive as strings with exactly 2 decimal places (e.g., `"1234.56"`).
- All datetime values SHALL survive as ISO 8601 strings (e.g., `"2025-01-15T10:30:00Z"`).
- No fields defined in the schema SHALL be lost or mutated during the round-trip.

**Validates: Requirements 21.9, 25.6, 25.4, 25.5**

---

## Error Handling

### Global Exception Handler

The Laravel exception handler (`app/Exceptions/Handler.php`) maps all exceptions to the standard JSON response envelope:

```php
public function render($request, Throwable $e): Response {
    if ($request->expectsJson()) {
        return match(true) {
            $e instanceof ValidationException => response()->json([
                'success' => false, 'data' => null,
                'message' => 'Validation failed.',
                'errors' => $e->errors(), 'meta' => null,
            ], 422),

            $e instanceof AuthenticationException => response()->json([
                'success' => false, 'data' => null,
                'message' => 'Unauthenticated.', 'errors' => null, 'meta' => null,
            ], 401),

            $e instanceof AuthorizationException => response()->json([
                'success' => false, 'data' => null,
                'message' => 'Forbidden.', 'errors' => null, 'meta' => null,
            ], 403),

            $e instanceof ModelNotFoundException => response()->json([
                'success' => false, 'data' => null,
                'message' => 'Resource not found.', 'errors' => null, 'meta' => null,
            ], 404),

            $e instanceof BudgetExceededException => response()->json([
                'success' => false, 'data' => null,
                'message' => $e->getMessage(),
                'errors' => ['budget' => [$e->getMessage()]], 'meta' => null,
            ], 422),

            $e instanceof TenantNotFoundException => response()->json([
                'success' => false, 'data' => null,
                'message' => 'Tenant not found.', 'errors' => null, 'meta' => null,
            ], 401),

            default => response()->json([
                'success' => false, 'data' => null,
                'message' => app()->isProduction() ? 'Server error.' : $e->getMessage(),
                'errors' => null, 'meta' => null,
            ], 500),
        };
    }
    return parent::render($request, $e);
}
```

### Domain-Specific Error Handling

| Scenario | HTTP Code | Error Detail |
|---|---|---|
| PR exceeds budget | 422 | Available balance + shortfall amount |
| Bid after deadline | 422 | Deadline timestamp |
| Invoice exceeds PO value | 422 | PO value + invoiced amount + discrepancy |
| Contract activation without bond | 422 | Missing document type |
| Duplicate email per tenant | 422 | Field-level error on `email` |
| Blacklisted supplier submitting bid | 403 | Supplier status reason |
| Audit log modification attempt | 403 | Security event logged |
| File MIME type mismatch | 422 | Allowed types list |
| File size exceeded | 422 | Max size + actual size |
| Tenant suspended | 401 | Tenant status |

### Frontend Error Handling

- **API errors**: Caught by Axios response interceptor, dispatched to TanStack Query's `onError` handler, displayed as toast notifications.
- **Network errors**: Detected by Axios, displayed as a persistent banner with retry action.
- **React Error Boundaries**: Wrap each major page section; display a fallback UI with a retry button.
- **Form validation errors**: Displayed inline below each field via React Hook Form's `formState.errors`.
- **Loading states**: Skeleton components shown during data fetching; spinners on mutation buttons.


---

## Testing Strategy

### Overview

The platform uses a dual testing approach: **example-based tests** for specific behaviors and edge cases, and **property-based tests** for universal invariants. Both are complementary and necessary for comprehensive coverage.

**Property-Based Testing Library**: [Pest PHP](https://pestphp.com/) with a custom property-based testing helper using [FakerPHP](https://fakerphp.org/) for data generation. For more advanced shrinking and generation, [eris/eris](https://github.com/giorgiosironi/eris) can be integrated.

### Test Suite Structure

```
tests/
├── Unit/
│   ├── Services/
│   │   ├── PurchaseRequestServiceTest.php
│   │   ├── BudgetServiceTest.php
│   │   ├── BidEvaluationServiceTest.php
│   │   ├── ApprovalWorkflowServiceTest.php
│   │   └── ...
│   ├── Repositories/
│   └── Traits/
│       └── GeneratesDocumentNumberTest.php
├── Feature/
│   ├── Api/V1/
│   │   ├── AuthTest.php
│   │   ├── PurchaseRequestTest.php
│   │   ├── TenderTest.php
│   │   ├── BidTest.php
│   │   ├── PurchaseOrderTest.php
│   │   ├── BudgetTest.php
│   │   ├── InvoiceTest.php
│   │   └── ...
│   ├── MultiTenant/
│   │   └── TenantIsolationTest.php
│   └── Workflows/
│       ├── ApprovalWorkflowTest.php
│       └── ProcurementLifecycleTest.php
├── Property/
│   ├── TenantIsolationPropertyTest.php
│   ├── DocumentNumberPropertyTest.php
│   ├── BudgetEnforcementPropertyTest.php
│   ├── BudgetEncumbranceRoundTripPropertyTest.php
│   ├── JwtClaimsPropertyTest.php
│   ├── BidEvaluationScorePropertyTest.php
│   ├── EvaluationCriteriaWeightsPropertyTest.php
│   ├── ApprovalWorkflowStateMachinePropertyTest.php
│   ├── BidDeadlineEnforcementPropertyTest.php
│   ├── AuditLogImmutabilityPropertyTest.php
│   ├── ApiResponseEnvelopePropertyTest.php
│   └── SerializationRoundTripPropertyTest.php
└── E2E/
    └── ProcurementLifecycleE2ETest.php   (Playwright/Cypress)
```

### Property-Based Test Configuration

Each property test runs a minimum of **100 iterations** with randomly generated inputs. Tests are tagged with the design property they validate.

```php
// tests/Property/DocumentNumberPropertyTest.php

/**
 * Feature: procurement-management-platform
 * Property 2: Document Number Uniqueness and Format
 */
it('generates unique PR numbers matching the required format', function () {
    $tenant = Tenant::factory()->create(['tenant_code' => 'ACME']);
    $year = now()->year;
    $generatedNumbers = [];
    $pattern = "/^PR-ACME-{$year}-\d+$/";

    // Run 100 iterations
    for ($i = 0; $i < 100; $i++) {
        $prNumber = app(PurchaseRequestService::class)->generatePRNumber($tenant);
        expect($prNumber)->toMatch($pattern);
        expect($generatedNumbers)->not->toContain($prNumber);
        $generatedNumbers[] = $prNumber;
    }

    expect(array_unique($generatedNumbers))->toHaveCount(100);
})->repeat(1); // Single test, loop internally for atomicity
```

```php
// tests/Property/BudgetEnforcementPropertyTest.php

/**
 * Feature: procurement-management-platform
 * Property 3: Budget Enforcement Invariant
 */
it('always rejects PRs that exceed available budget balance', function () {
    $tenant = Tenant::factory()->create();
    $department = Department::factory()->for($tenant)->create();

    // Run 100 random budget/PR value combinations
    for ($i = 0; $i < 100; $i++) {
        $availableBalance = fake()->randomFloat(2, 100, 100000);
        $prValue = $availableBalance + fake()->randomFloat(2, 0.01, 10000); // Always exceeds

        Budget::factory()->for($department)->create([
            'total_amount' => $availableBalance,
            'encumbered_amount' => 0,
            'spent_amount' => 0,
        ]);

        $result = app(BudgetService::class)->validatePRAgainstBudget($department, $prValue);

        expect($result->isValid())->toBeFalse();
        expect($result->getAvailableBalance())->toBe($availableBalance);
        expect($result->getShortfall())->toBe($prValue - $availableBalance);
    }
});
```

```php
// tests/Property/SerializationRoundTripPropertyTest.php

/**
 * Feature: procurement-management-platform
 * Property 12: JSON Serialization Round-Trip
 */
it('preserves all field values through JSON serialization round-trip', function () {
    for ($i = 0; $i < 100; $i++) {
        $pr = PurchaseRequest::factory()->withItems(rand(1, 5))->create();

        // Serialize to API response
        $resource = new PurchaseRequestResource($pr);
        $json = $resource->toJson();

        // Parse back
        $parsed = json_decode($json, true);

        // Verify round-trip integrity
        expect($parsed['id'])->toBe($pr->id);
        expect($parsed['pr_number'])->toBe($pr->pr_number);
        expect($parsed['estimated_total'])->toBe(number_format($pr->estimated_total, 2, '.', ''));
        expect($parsed['created_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');
        expect(Str::isUuid($parsed['id']))->toBeTrue();
    }
});
```

### Unit Test Coverage Requirements

- Minimum **80% code coverage** on all Service and Repository classes.
- All business rule enforcement methods must have 100% branch coverage.
- Key areas requiring thorough unit testing:
  - `BudgetService::validatePRAgainstBudget()`
  - `BudgetService::encumberAmount()` / `releaseEncumbrance()`
  - `PurchaseRequestService::generatePRNumber()`
  - `PurchaseOrderService::generatePONumber()`
  - `BidEvaluationService::calculateWeightedScore()`
  - `ApprovalWorkflowService::advance()` / `reject()` / `returnForRevision()`
  - `TenantIdentificationMiddleware::handle()`
  - `FileManagementService::validate()`

### Feature Test Coverage

Feature tests use PHPUnit/Pest with a dedicated test database. Each test:
1. Creates a fresh tenant with seeded roles and permissions.
2. Authenticates as the appropriate role.
3. Makes HTTP requests to the API.
4. Asserts HTTP status codes, response structure, and database state.

Key feature test scenarios:
- **Authentication**: Successful login, wrong password, account lockout after 5 failures, JWT expiry, password reset flow.
- **Multi-tenant isolation**: User from Tenant A cannot read/write Tenant B data through any endpoint.
- **Approval workflows**: Single-level, multi-level (up to 10 levels), rejection at each level, return for revision, parallel approval, escalation notification.
- **Budget enforcement**: PR within budget, PR over budget, PO encumbrance, encumbrance release, over-budget exception.
- **Bid deadline**: Bid before deadline succeeds, bid after deadline rejected.
- **Contract activation**: Blocked without performance bond, succeeds with bond.
- **Audit log immutability**: PUT/DELETE on audit log returns 403.
- **File upload**: Valid file accepted, oversized file rejected, wrong MIME type rejected.

### End-to-End Tests

Playwright tests simulate the full procurement lifecycle:

1. Tenant_Admin creates department and budget.
2. Department_Staff creates and submits a PR.
3. Procurement_Officer approves the PR.
4. Procurement_Officer creates and publishes a Tender.
5. Supplier submits a Bid.
6. Committee_Member evaluates the Bid.
7. Procurement_Officer selects winner and issues PO.
8. Store_Manager creates Goods Receipt.
9. Supplier submits Invoice.
10. Finance_Officer approves Invoice and processes Payment.

### CI/CD Test Execution

The GitHub Actions pipeline:
1. Runs unit and feature tests in parallel (`php artisan test --parallel`).
2. Runs property-based tests (100 iterations each).
3. Runs frontend unit tests (`npm run test:ci`).
4. Runs E2E tests against a Docker Compose test environment.
5. Generates coverage report and fails if coverage drops below 80%.
6. Blocks PR merge if any test fails.

