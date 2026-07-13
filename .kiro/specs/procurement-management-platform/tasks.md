# Implementation Plan: Procurement Management Platform

## Overview

This plan converts the PMP design into incremental coding tasks for a Laravel 12 backend, Next.js 15 frontend, and Flutter mobile app. Tasks are ordered by dependency — infrastructure and core models first, then domain services, then UI layers. Each task references the specific requirements it satisfies.

---

## Tasks

- [x] 1. Project Foundation & Infrastructure
  - [x] 1.1 Initialize Laravel 12 backend project with Docker Compose (app, nginx, mysql, redis, queue-worker, scheduler, soketi services), configure `.env` structure, and set up GitHub Actions CI/CD pipeline
    - Create `docker-compose.yml` and `docker-compose.prod.yml` with all services defined in the design
    - Configure Nginx with TLS termination, HSTS, X-Frame-Options, Content-Security-Policy headers
    - Create `.github/workflows/ci.yml` with test-backend, test-frontend, and build-and-deploy jobs
    - Configure three named Redis queues: `notifications`, `default`, `reports`
    - _Requirements: 20.1, 20.2, 20.4, 20.5, 20.6, 20.7, 20.9_

  - [x] 1.2 Initialize Next.js 15 frontend project with TypeScript, Tailwind CSS, ShadCN UI, TanStack Query, Zustand, Recharts, Framer Motion, React Hook Form, and Zod
    - Scaffold `src/app`, `src/components`, `src/hooks`, `src/lib`, `src/providers`, `src/store`, `src/types` directory structure
    - Configure `QueryProvider`, `ThemeProvider`, `AuthProvider`
    - Set up Axios client with request/response interceptors and `X-Request-ID` header injection
    - Configure Zustand stores: `authStore`, `notificationStore`, `uiStore`
    - _Requirements: 22.5, 22.6, 22.7, 18.10_

  - [x] 1.3 Initialize Flutter mobile project with Clean Architecture structure (core, features layers), Riverpod state management, Dio HTTP client, Hive local database, and Connectivity Plus
    - Scaffold `lib/core/network`, `lib/core/errors`, `lib/features/{auth,dashboard,tenders,bids,purchase_orders,invoices,notifications}` directories
    - Configure `ApiClient` with `AuthInterceptor` and `TenantInterceptor`
    - Set up Hive boxes for offline caching with 24-hour TTL for list data and 1-hour TTL for detail data
    - _Requirements: 22.8, 22.9_


- [x] 2. Database Schema & Core Models
  - [x] 2.1 Create all database migrations for master tables (`tenants`, `system_admins`) and tenant-scoped tables (`users`, `departments`, `budgets`, `budget_transactions`) with UUID PKs, foreign keys, indexes, and unique constraints
    - Use `DECIMAL(15,2)` for all monetary columns; include `deleted_at` soft-delete on applicable tables
    - Add composite unique constraints: `(tenant_id, email)` on users
    - _Requirements: 19.1, 19.2, 19.3, 19.4, 19.5, 19.6, 19.10_

  - [x] 2.2 Create migrations for procurement tables: `purchase_requests`, `purchase_request_items`, `purchase_request_history`, `approval_workflows`, `approval_workflow_levels`, `approvals`
    - Add composite unique constraint `(tenant_id, pr_number)` on purchase_requests
    - Add indexes on `status`, `tenant_id`, `department_id`, `submitted_by`, `created_at`
    - _Requirements: 19.1, 19.3, 19.4_

  - [x] 2.3 Create migrations for supplier and tender tables: `suppliers`, `supplier_documents`, `supplier_performance`, `tenders`, `tender_documents`, `bids`, `bid_documents`, `bid_evaluation_criteria`, `bid_evaluations`
    - Add composite unique `(tenant_id, reference_number)` on tenders; `(tenant_id, tender_id, supplier_id)` on bids; `(tenant_id, bid_id, criteria_id, evaluator_id)` on bid_evaluations
    - _Requirements: 19.1, 19.3, 19.4_

  - [x] 2.4 Create migrations for order and contract tables: `purchase_orders`, `purchase_order_items`, `contracts`, `contract_amendments`, `contract_documents`
    - Add composite unique `(tenant_id, po_number)` on purchase_orders; `(tenant_id, contract_number)` on contracts
    - Add indexes on `required_delivery_date`, `end_date`, `status`
    - _Requirements: 19.1, 19.3, 19.4_

  - [x] 2.5 Create migrations for operations tables: `goods_receipts`, `goods_receipt_items`, `warehouses`, `inventory`, `invoices`, `invoice_items`, `payments`, `notifications`, `audit_logs`, `market_research`, `market_research_items`
    - `audit_logs` must have NO `updated_at` or `deleted_at` columns (append-only by design)
    - Add composite unique `(tenant_id, warehouse_id, item_code)` on inventory
    - _Requirements: 17.5, 19.1, 19.3, 19.4_

  - [x] 2.6 Create Eloquent models for all entities with `HasTenantScope` trait, `HasUuids` trait, fillable arrays, casts (UUID, DECIMAL, timestamps), and relationships
    - Implement `HasTenantScope` trait: global scope appending `WHERE tenant_id = ?`, auto-set `tenant_id` on `creating` event
    - Implement `GeneratesDocumentNumber` trait for PR and PO number generation
    - Implement `HasAuditLog` trait for automatic audit log dispatch on model events
    - _Requirements: 1.2, 1.4, 19.1_

  - [x] 2.7 Create database seeders for system roles (8 roles), Spatie permissions (full RBAC matrix), and a demo tenant with sample data; create model factories for all major entities
    - Seed all 20 permissions from the RBAC matrix in the design
    - Create factories: `TenantFactory`, `UserFactory`, `DepartmentFactory`, `BudgetFactory`, `PurchaseRequestFactory`, `SupplierFactory`, `TenderFactory`, `BidFactory`, `PurchaseOrderFactory`, `ContractFactory`, `InvoiceFactory`, `PaymentFactory`
    - _Requirements: 3.1, 19.8, 19.9_



- [x] 3. Multi-Tenant Architecture & Authentication
  - [x] 3.1 Implement `TenantIdentificationMiddleware` that resolves the active tenant from subdomain, `X-Tenant-ID` header, or JWT `tenant_id` claim; sets `app('tenant')` context; rejects unresolvable requests with HTTP 401 and dispatches an audit log entry
    - Cache resolved tenant in Redis for 60 seconds to avoid repeated DB lookups
    - Return HTTP 401 with audit log entry when tenant is suspended or not found
    - _Requirements: 1.1, 1.3, 1.5_

  - [x] 3.2 Implement JWT authentication: `AuthService` with login, logout (Redis blacklist via `jti`), refresh, password reset request, and password reset confirmation; `AuthController` with all `/api/v1/auth/*` endpoints
    - JWT payload must include `user_id`, `tenant_id`, `role`, `permissions`, `iat`, `exp`, `jti`
    - Invalidate token on logout by storing `jti` in Redis with TTL matching remaining token lifetime
    - Send time-limited (60-minute) password reset link via queued email job
    - _Requirements: 2.1, 2.5, 2.6, 2.9_

  - [x] 3.3 Implement account lockout: increment `failed_login_attempts` counter on each failed login; lock account and send password-reset email when threshold (default: 5) is reached; enforce session timeout by rejecting JWTs older than tenant-configured timeout
    - _Requirements: 2.2, 2.3, 2.4_

  - [x] 3.4 Implement RBAC with Spatie Laravel Permission: seed all 8 roles and 20 permissions; implement `RoleMiddleware` that checks permissions on every protected route returning HTTP 403 on failure; implement role assignment and revocation API with cache invalidation within 5 seconds
    - _Requirements: 3.1, 3.2, 3.3, 3.5, 3.6, 3.9_

  - [x] 3.5 Implement rate limiting: 60 requests/minute per IP on auth endpoints (HTTP 429 on exceed); 300 requests/minute per user/IP on API endpoints; implement CSRF token endpoint for browser clients
    - _Requirements: 2.7, 2.8_



- [x] 4. User & Department Management
  - [x] 4.1 Implement `UserManagementService` and `UserController`: CRUD for users within tenant scope, role assignment/revocation, welcome email with 24-hour password-setup link on user creation, unique email enforcement per tenant
    - Reject deletion of users with active PRs or POs; return count of linked active records
    - _Requirements: 4.1, 4.2, 4.6, 4.8, 4.9_

  - [x] 4.2 Implement `DepartmentController`: CRUD for departments; prevent PR submission under deactivated departments while preserving historical records; support parent-child department hierarchy
    - _Requirements: 4.3, 4.4, 4.5_

  - [x] 4.3 Implement frontend: User management pages (paginated/searchable user list, create/edit user form with role assignment, deactivate user action) using ShadCN DataTable, React Hook Form, and Zod validation
    - _Requirements: 4.1, 4.6, 22.6, 22.7_

  - [x] 4.4 Implement frontend: Department management pages (department list, create/edit/deactivate department forms, hierarchical department display)
    - _Requirements: 4.3, 4.4, 22.6_



- [x] 5. Budget Management
  - [x] 5.1 Implement `BudgetService`: annual budget allocation per department, `validatePRAgainstBudget()`, `encumberAmount()`, `releaseEncumbrance()`, `recordExpenditure()`, `transferBudget()`, real-time utilization report; send threshold notifications at 75% and 90% consumption
    - All monetary operations must use DECIMAL arithmetic to prevent floating-point errors
    - Prevent any action causing total committed + actual expenditure to exceed 100% unless Finance_Officer approves exception
    - _Requirements: 13.1, 13.2, 13.3, 13.4, 13.5, 13.6, 13.7, 13.8, 13.9, 13.10_
    - Status: in_progress

  - [x] 5.2 Implement `BudgetController` with all `/api/v1/budgets/*` endpoints including budget transfer and utilization report endpoint
    - _Requirements: 13.1, 13.8, 13.10_
    - Status: in_progress

  - [x] 5.3 Write property-based tests for `BudgetService`: Property 3 (budget enforcement invariant — PRs exceeding available balance are always rejected with correct shortfall amount) and Property 4 (encumbrance round-trip — issuing then cancelling a PO restores original balance)
    - Run 100 iterations with random budget amounts and PR values
    - _Requirements: 13.2, 13.3, 13.4, 13.5, 21.6_

  - [x] 5.4 Implement frontend: Budget management pages (budget allocation form per department/fiscal year, real-time utilization dashboard with Recharts bar chart, budget transfer form)
    - _Requirements: 13.1, 13.10, 22.5, 22.10_



- [x] 6. Purchase Request Management
  - [x] 6.1 Implement `PurchaseRequestService`: `create()`, `update()` (draft only), `submit()` with budget validation, `cancel()`, `generatePRNumber()` in format `PR-{TENANT_CODE}-{YEAR}-{SEQUENCE}`, full history tracking on every status transition
    - _Requirements: 5.1, 5.2, 5.3, 5.4, 5.5, 5.6, 5.7_

  - [x] 6.2 Implement `PurchaseRequestController` with all `/api/v1/purchase-requests/*` endpoints including file attachment upload, search by PR number/department/status/date range/submitter
    - _Requirements: 5.8, 5.10_

  - [x] 6.3 Write property-based tests for `generatePRNumber()`: Property 2 (uniqueness and format — 100 sequential generations produce unique numbers matching `PR-{CODE}-{YEAR}-{SEQ}` pattern with no collisions across tenants)
    - _Requirements: 5.1, 21.8_

  - [x] 6.4 Implement frontend: Purchase request list page (filterable DataTable with status badges), create PR form (dynamic line items, file attachments, budget validation feedback), PR detail page with history timeline
    - _Requirements: 5.2, 5.5, 5.7, 5.8, 22.5, 22.6, 22.7_



- [x] 7. Approval Workflow Engine
  - [x] 7.1 Implement `ApprovalWorkflowService`: configurable 1–10 level chains per document type, `advance()`, `reject()` (mandatory reason), `returnForRevision()` (mandatory comments), parallel approval support (all approvers must approve before advancing), escalation after configured hours
    - _Requirements: 6.1, 6.3, 6.4, 6.5, 6.6, 6.7, 6.10_

  - [x] 7.2 Implement approval workflow configuration API (`/api/v1/approval-workflows`) and approval action endpoints (`/api/v1/approvals/{id}/approve|reject|return`); implement `ProcessEscalations` artisan command scheduled every hour
    - Escalation falls back to Tenant_Admin when no supervisor is configured
    - _Requirements: 6.2, 6.8, 6.9_

  - [x] 7.3 Write property-based tests for `ApprovalWorkflowService`: Property 8 (state machine progression — for any L-level workflow, approval at level k < L advances to k+1; approval at level L sets status to `approved`; rejection at any level sets `rejected`; return sets `revision_required`)
    - _Requirements: 6.3, 6.4, 6.5, 21.5_

  - [x] 7.4 Implement frontend: Approval workflow configuration page (drag-and-drop level builder, role/user assignment per level, parallel toggle); pending approvals dashboard with document preview and approve/reject/return actions
    - _Requirements: 6.8, 22.5_



- [x] 8. Supplier Management
  - [x] 8.1 Implement `SupplierManagementService`: self-registration (public endpoint), verification workflow (pending → active), blacklisting with documented reason, compliance document upload/versioning, performance metrics calculation (on-time delivery rate, quality acceptance rate)
    - Only `active` suppliers can submit bids or receive POs
    - _Requirements: 7.1, 7.2, 7.3, 7.4, 7.5, 7.6, 7.9, 7.10_

  - [x] 8.2 Implement `SupplierController` with all `/api/v1/suppliers/*` endpoints; implement `SendContractRenewalAlerts` artisan command for 30-day document expiry reminders
    - _Requirements: 7.7, 7.8_

  - [x] 8.3 Implement frontend: Supplier list page (filterable by status/category), supplier detail page (profile, documents, performance metrics, transaction history), supplier registration form (public-facing)
    - _Requirements: 7.6, 7.7, 22.6_



- [x] 9. Tender & Bidding Management
  - [x] 9.1 Implement `TenderService`: create, publish (notify active suppliers by category), cancel (notify bidding suppliers), extend deadline (before original deadline), automatic closure via scheduler; support open/restricted/single-source tender types
    - _Requirements: 8.1, 8.2, 8.3, 8.6, 8.8, 8.9, 8.10_

  - [x] 9.2 Implement bid submission API: validate submission timestamp against deadline (reject if past deadline), enforce one bid per supplier per tender with revision allowed before deadline, prevent suppliers from viewing other suppliers' bids
    - _Requirements: 8.4, 8.5, 8.7_

  - [x] 9.3 Write property-based tests for bid deadline enforcement: Property 9 (for any tender deadline D and submission timestamp T, T < D succeeds and T ≥ D is rejected — 100 random timestamp pairs)
    - _Requirements: 8.4, 8.6, 21.8_

  - [x] 9.4 Implement frontend: Tender management pages (tender list, create/edit tender form with document upload, tender detail with bid list for Procurement_Officer); supplier-facing open tender list and bid submission form
    - _Requirements: 8.1, 8.3, 22.6_



- [ ] 10. Bid Evaluation System
  - [x] 10.1 Implement `BidEvaluationService`: configurable weighted criteria (weights must sum to 100), `calculateWeightedScore()` using DECIMAL arithmetic, score blinding (hide scores until all evaluators submit), ranked comparison report, winner selection with mandatory justification
    - Reject score modification after evaluation is finalized; log the attempt
    - Support price-only evaluation mode for low-value procurements
    - _Requirements: 9.1, 9.2, 9.3, 9.4, 9.5, 9.7, 9.8, 9.9, 9.10_

  - [x] 10.2 Implement bid evaluation API endpoints (`/api/v1/tenders/{id}/evaluation/*`); notify winning and non-winning suppliers on winner selection
    - _Requirements: 9.6_

  - [x] 10.3 Write property-based tests: Property 6 (weighted score = Σ(score × weight / 100) with DECIMAL precision — 100 random score/weight combinations); Property 7 (criteria weights must sum to 100 — system rejects configurations that don't)
    - _Requirements: 9.1, 9.3_

  - [x] 10.4 Implement frontend: Bid evaluation page (criteria definition form, score entry grid per evaluator, ranked comparison table with Recharts bar chart, winner selection with justification modal)
    - _Requirements: 9.4, 9.5, 22.5_



- [ ] 11. Purchase Order Management
  - [x] 11.1 Implement `PurchaseOrderService`: `generate()` with unique PO number `PO-{TENANT_CODE}-{YEAR}-{SEQUENCE}`, `issue()`, `accept()`, `reject()`, `amend()` (pre-acceptance free; post-acceptance requires supplier acknowledgment), `cancel()` with budget encumbrance release
    - _Requirements: 10.1, 10.2, 10.3, 10.4, 10.5, 10.8, 10.9, 10.10_

  - [x] 11.2 Implement PO delivery tracking: `SendDeliveryReminders` scheduler (7-day and 1-day reminders to supplier); `MarkOverduePOs` scheduler (flag POs past delivery date without confirmed GRN as `overdue` and notify Procurement_Officer)
    - _Requirements: 10.6, 10.7_

  - [x] 11.3 Write property-based tests for `generatePONumber()`: Property 2 applied to POs (100 sequential generations produce unique numbers matching `PO-{CODE}-{YEAR}-{SEQ}` with no cross-tenant collisions)
    - _Requirements: 10.1, 21.8_

  - [x] 11.4 Implement frontend: Purchase order list page, PO detail page (line items, delivery tracking, status timeline), PO creation form, amend PO modal
    - _Requirements: 10.2, 10.9, 22.6_



- [ ] 12. Contract Lifecycle Management
  - [x] 12.1 Implement `ContractService`: create contract linked to PO or Tender, `activate()` (blocked without performance bond document — returns HTTP 422 with descriptive error), `amend()` with documented reason and version history, `terminate()` with reason, contract value consumption tracking (alert at 80%)
    - _Requirements: 11.1, 11.2, 11.5, 11.6, 11.7, 11.8, 11.9, 11.10_

  - [x] 12.2 Implement contract renewal alert scheduler: send notification 60 days before end date; send escalation notification 30 days before end date if no renewal action taken
    - _Requirements: 11.3, 11.4_

  - [x] 12.3 Implement frontend: Contract list page, contract detail page (parties, scope, value consumption progress bar, amendment history, documents), contract creation form, amendment modal, termination modal
    - _Requirements: 11.1, 11.5, 22.6_



- [ ] 13. Goods Receiving & Inventory
  - [x] 13.1 Implement `GoodsReceiptService`: create GRN referencing PO number and delivery note, committee inspection workflow (Store_Manager designates ≥2 Committee_Members), majority-vote acceptance/rejection per line item, update PO received quantity to `partially_received` or `fully_received`, generate Delivery_Note document
    - Validate received quantity does not exceed outstanding PO quantity
    - _Requirements: 12.1, 12.2, 12.3, 12.4, 12.5, 12.6, 12.10_

  - [x] 13.2 Implement `InventoryService`: real-time stock balance per item per warehouse updated on GRN acceptance, low-stock notification when stock falls below reorder threshold, inventory search by item/category/warehouse/stock level
    - _Requirements: 12.7, 12.8, 12.9_

  - [x] 13.3 Implement frontend: Goods receipt list and creation form (PO lookup, line item quantities, committee assignment), inspection result entry page, inventory list page with stock level indicators and reorder alerts
    - _Requirements: 12.1, 12.8, 22.6_



- [ ] 14. Invoice & Payment Processing
  - [x] 14.1 Implement `InvoiceService`: supplier invoice submission referencing PO or Contract, validate invoiced amount does not exceed PO/Contract value and goods have been received and accepted, route through Invoice approval workflow, reject with discrepancy details if over-value
    - _Requirements: 14.1, 14.2, 14.3, 14.4_

  - [x] 14.2 Implement `PaymentService`: create payment record on invoice approval, partial payment support (status → `partially_paid` until settled), payment due date reminder scheduler (5 days before due), payment schedule report
    - _Requirements: 14.5, 14.6, 14.7, 14.8, 14.9, 14.10_

  - [x] 14.3 Implement frontend: Invoice list page (filterable by status/supplier/date), invoice detail page (line items, PO/contract reference, approval history), payment management page (payment schedule, record payment form)
    - _Requirements: 14.1, 14.10, 22.6_



- [x] 15. Notifications System
  - [x] 15.1 Implement `NotificationService` with Laravel Echo + Soketi WebSocket broadcasting: private tenant-scoped channels (`private-tenant.{tenantId}.user.{userId}`), channel authorization in `routes/channels.php`, real-time in-app delivery, database persistence with `is_read` tracking
    - _Requirements: 15.1, 15.3, 15.6, 15.10_

  - [x] 15.2 Implement all 15 notification event types and their listeners: `PurchaseRequestSubmitted`, `PurchaseRequestStatusChanged`, `TenderPublished`, `BidDeadlineApproaching`, `BidEvaluationCompleted`, `PurchaseOrderIssued`, `PurchaseOrderStatusChanged`, `GoodsReceiptCreated`, `InvoiceSubmitted`, `InvoiceStatusChanged`, `PaymentProcessed`, `BudgetThresholdReached`, `ContractRenewalAlert`, `AccountLocked`, `LowStockAlert`
    - _Requirements: 15.5_

  - [x] 15.3 Implement queue-based email notifications via `SendNotificationEmailJob` on `notifications` queue; retry 3 times with exponential backoff; log failure and alert System_Admin after 3 failed attempts; respect per-tenant notification configuration
    - _Requirements: 15.2, 15.8, 15.9_

  - [x] 15.4 Implement Notification API endpoints: list (paginated, filterable by event type/date), mark individual as read, mark all as read, unread count
    - _Requirements: 15.4, 15.7_

  - [x] 15.5 Implement frontend: Notification bell with unread count badge, notification dropdown, full notification history page with filters; integrate Laravel Echo client for real-time updates
    - _Requirements: 15.6, 15.7, 22.5_



- [x] 16. Reporting & Analytics
  - [x] 16.1 Implement `ReportingService`: role-specific KPI dashboard data (total PRs by status, active tenders, PO fulfillment rate, budget utilization %, pending approvals count, overdue deliveries count); procurement timeline report (avg cycle time PR→PO, filterable by dept/category/date)
    - Cache report data in Redis for 5 minutes; scope all data to requesting user's tenant and role permissions
    - _Requirements: 16.1, 16.2, 16.9_

  - [x] 16.2 Implement spending analytics report (expenditure by dept/category/supplier with month-over-month trends), supplier performance report, tender statistics report, financial summary report (invoiced/paid/outstanding/budget variance)
    - _Requirements: 16.3, 16.4, 16.5, 16.6_

  - [x] 16.3 Implement report export: synchronous PDF/Excel generation for datasets ≤10,000 rows (available within 30 seconds); async `GenerateReportJob` on `reports` queue for >10,000 rows with notification when ready
    - _Requirements: 16.7, 16.8_

  - [x] 16.4 Implement frontend: Role-specific dashboard page with Recharts animated charts (Framer Motion) and KPI widgets; report pages with date/dept/category/status/supplier filters and PDF/Excel export buttons
    - _Requirements: 16.1, 16.10, 22.1, 22.10_



- [x] 17. Audit Logging & Traceability
  - [x] 17.1 Implement `AuditService` with async `WriteAuditLogJob` on `default` queue (max 5-second write latency): capture user UUID/role/tenant_id, action type, entity type/UUID, before/after JSON diff, IP address, UTC timestamp, request ID; implement `AuditTrailMiddleware`
    - `audit_logs` table is append-only — no UPDATE or DELETE operations permitted at any layer
    - _Requirements: 17.1, 17.2, 17.3, 17.4, 17.5, 17.9_

  - [x] 17.2 Implement Audit Log API endpoint (`GET /api/v1/audit-logs`) with search/filter by user/action type/entity type/date range/IP; Tenant_Admin scoped to own tenant; System_Admin can access all tenants; reject any PUT/DELETE on audit logs with HTTP 403
    - _Requirements: 17.6, 17.7, 17.8_

  - [x] 17.3 Write property-based tests: Property 10 (audit log immutability — for any audit log record, PUT/DELETE returns HTTP 403 regardless of role; total record count is monotonically non-decreasing — 100 random create/read/attempt-delete sequences)
    - _Requirements: 17.5, 17.6, 21.1_

  - [x] 17.4 Implement frontend: Audit log viewer page with advanced filters (user, action type, entity, date range, IP), paginated results table, export to CSV
    - _Requirements: 17.7, 22.6_



- [ ] 18. File Management
  - [x] 18.1 Implement `FileManagementService`: validate MIME type against allowed list (PDF, DOCX, XLSX, PNG, JPG, JPEG), validate file size ≤10 MB, generate non-guessable storage key (UUID + SHA-256 hash), store in tenant-scoped path `{tenant_id}/{entity_type}/{uuid}.{ext}`, soft delete with audit log entry
    - _Requirements: 23.1, 23.2, 23.3, 23.4, 23.6, 23.9_

  - [x] 18.2 Implement file download with tenant authorization check (requesting user must belong to same tenant as file owner); implement `ProcessSupplierDocumentScanJob` as virus scan integration point (async, rejects file if scan fails); configure S3-compatible storage backend via environment variables
    - _Requirements: 23.5, 23.7, 23.8, 23.10_



- [x] 19. API Documentation, Caching & Performance
  - [x] 19.1 Implement OpenAPI 3.0 specification with Swagger UI at `/api/documentation`: document all endpoints with request schemas, response schemas, authentication requirements, and example payloads; include `X-Request-ID` header in all responses
    - _Requirements: 18.6, 18.7, 18.10_

  - [x] 19.2 Implement Redis caching for role permissions (300s TTL), tenant configuration (300s TTL), and budget summaries (300s TTL); implement immediate cache invalidation on role changes and budget updates; implement slow query logging for queries exceeding 1 second
    - _Requirements: 24.3, 24.4, 24.10_

  - [x] 19.3 Implement health check endpoint (`GET /api/health`) returning database connection status, Redis connection status, and queue worker availability; return HTTP 200 when all healthy, HTTP 503 when degraded
    - _Requirements: 20.10_



- [x] 20. Frontend Polish & Tenant Management UI
  - [x] 20.1 Implement frontend: dark/light mode toggle with `localStorage` persistence under `pmp-theme` key; responsive layout rendering correctly from 320px to 2560px without horizontal scrolling; WCAG 2.1 Level AA compliance (keyboard navigation, color contrast, ARIA labels)
    - _Requirements: 22.2, 22.3, 22.4_

  - [x] 20.2 Implement frontend: loading skeleton components for all data-fetching pages; React Error Boundary components wrapping each major page section with retry action; optimistic updates for status-change actions (approve, reject, mark-as-read)
    - _Requirements: 22.5, 22.7_

  - [x] 20.3 Implement frontend: Tenant management pages for System_Admin (tenant list, register tenant form, suspend/reactivate tenant actions, tenant analytics dashboard)
    - _Requirements: 1.6, 1.8_

  - [x] 20.4 Implement Flutter supplier portal: login screen, dashboard (active tenders, POs, invoice status), open tenders list, bid submission form, purchase orders list (accept/reject), invoice submission form, payment tracking screen, notifications screen
    - _Requirements: 22.8, 22.9_

  - [x] 20.5 Implement Flutter offline support: Hive local caching for dashboard/tender/PO data (24h TTL for lists, 1h for details), Connectivity Plus network detection, offline banner display, write operation queue with sync on reconnect
    - _Requirements: 22.9_



- [ ] 21. Testing Suite
  - [ ] 21.1 Write property-based tests: Property 1 (tenant data isolation — for any two distinct tenants A and B, queries in context of B never return records belonging to A — 100 random entity types and record combinations across all 15 entity types)
    - _Requirements: 1.2, 1.4, 21.3_

  - [~] 21.2 Write property-based tests: Property 5 (JWT claims completeness — for any valid user/tenant/role combination, issued JWT always contains `user_id`, `tenant_id`, `role`, `exp`, `iat`, `jti` with correct values — 100 random user/role combinations)
    - _Requirements: 2.1, 21.4_

  - [~] 21.3 Write property-based tests: Property 11 (API response envelope consistency — for any successful request, response contains `success:true`, `data`, `message`, `errors:null`, `meta`; for any validation failure, `success:false`, `errors` object with field arrays — 100 random valid and invalid requests per endpoint)
    - _Requirements: 18.2, 18.3, 21.2_

  - [~] 21.4 Write property-based tests: Property 12 (JSON serialization round-trip — for any entity, serializing to API response JSON and parsing back produces identical field values; UUIDs remain valid UUID v4; monetary values remain strings with exactly 2 decimal places; datetimes remain ISO 8601 — 100 random entities per type)
    - _Requirements: 25.4, 25.5, 25.6, 21.9_

  - [~] 21.5 Write multi-tenant isolation feature tests: verify that authenticated users from Tenant A receive HTTP 404 (not 403) when attempting to read, update, or delete any resource belonging to Tenant B through every API endpoint
    - _Requirements: 1.2, 1.4, 21.3_

  - [~] 21.6 Write authentication feature tests: successful login returns JWT with correct claims; wrong password increments counter; 5th failure locks account and sends email; expired JWT returns HTTP 401; password reset flow completes successfully; locked account cannot authenticate
    - _Requirements: 2.1, 2.2, 2.3, 2.4, 2.5, 21.4_

  - [~] 21.7 Write end-to-end Playwright test simulating the full procurement lifecycle: Tenant_Admin creates dept + budget → Department_Staff creates and submits PR → Procurement_Officer approves PR and creates/publishes Tender → Supplier submits Bid → Committee_Member evaluates Bid → Procurement_Officer selects winner and issues PO → Store_Manager creates GRN → Supplier submits Invoice → Finance_Officer approves Invoice and processes Payment
    - _Requirements: 21.7_


## Task Dependency Graph

```json
{
  "waves": [
    { "wave": 1, "tasks": ["1.1", "1.2", "1.3"] },
    { "wave": 2, "tasks": ["2.1", "2.2", "2.3", "2.4", "2.5"] },
    { "wave": 3, "tasks": ["2.6", "2.7"] },
    { "wave": 4, "tasks": ["3.1", "3.2", "3.3", "3.4", "3.5"] },
    { "wave": 5, "tasks": ["4.1", "4.2"] },
    { "wave": 6, "tasks": ["4.3", "4.4", "5.1", "5.2"] },
    { "wave": 7, "tasks": ["5.3", "5.4", "6.1", "6.2"] },
    { "wave": 8, "tasks": ["6.3", "6.4", "7.1", "7.2"] },
    { "wave": 9, "tasks": ["7.3", "7.4", "8.1", "8.2"] },
    { "wave": 10, "tasks": ["8.3", "9.1", "9.2", "15.1"] },
    { "wave": 11, "tasks": ["9.3", "9.4", "10.1", "10.2", "15.2", "15.3", "15.4", "18.1"] },
    { "wave": 12, "tasks": ["10.3", "10.4", "11.1", "11.2", "15.5", "18.2"] },
    { "wave": 13, "tasks": ["11.3", "11.4", "12.1", "12.2", "13.1", "13.2", "17.1", "17.2"] },
    { "wave": 14, "tasks": ["12.3", "13.3", "14.1", "14.2", "16.1", "16.2", "17.3", "17.4"] },
    { "wave": 15, "tasks": ["14.3", "16.3", "16.4", "19.1", "19.2", "19.3"] },
    { "wave": 16, "tasks": ["20.1", "20.2", "20.3", "20.4", "20.5"] },
    { "wave": 17, "tasks": ["21.1", "21.2", "21.3", "21.4", "21.5", "21.6", "21.7"] }
  ]
}
```

## Notes

- All backend tasks produce Laravel 12 PHP code following the Repository + Service Pattern as defined in the design document.
- All frontend tasks produce Next.js 15 TypeScript code using the App Router, ShadCN UI components, React Hook Form + Zod for forms, and TanStack Query for server state.
- All Flutter tasks produce Dart code following Clean Architecture with Riverpod state management.
- Property-based tests (tasks 5.3, 6.3, 7.3, 9.3, 10.3, 11.3, 17.3, 21.1–21.4) must run a minimum of 100 iterations each.
- Every task that creates or modifies data must ensure all queries are scoped to the active tenant via the `HasTenantScope` global scope.
- Monetary values must always use `DECIMAL(15,2)` in the database and be serialized as strings with exactly 2 decimal places in API responses.
- The `audit_logs` table is strictly append-only — no UPDATE or DELETE operations are permitted at any layer of the application.
- Tasks within the same phase that have no inter-dependencies can be executed in parallel.
