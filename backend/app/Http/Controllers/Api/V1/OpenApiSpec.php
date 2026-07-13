<?php

namespace App\Http\Controllers\Api\V1;

/**
 * @OA\Info(
 *     title="Procurement Management Platform API",
 *     version="1.0.0",
 *     description="Enterprise-grade multi-tenant SaaS procurement platform API. Authentication: JWT Bearer token via POST /api/v1/auth/login (Authorization: Bearer token). Tenant identification: X-Tenant-ID header, subdomain, or JWT claim. All responses follow a standard envelope with success, data, message, errors, and meta fields. Every response includes X-Request-ID header. Rate limits: 60/min auth, 300/min API.",
 *     @OA\Contact(email="api@platform.com", name="PMP API Support"),
 *     @OA\License(name="Proprietary")
 * )
 *
 * @OA\Server(
 *     url="/api/v1",
 *     description="API v1"
 * )
 *
 * @OA\SecurityScheme(
 *     securityScheme="bearerAuth",
 *     type="http",
 *     scheme="bearer",
 *     bearerFormat="JWT",
 *     description="JWT Bearer token. Obtain via POST /api/v1/auth/login. Include as: Authorization: Bearer <token>"
 * )
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Common Headers
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * @OA\Parameter(
 *     parameter="XTenantID",
 *     name="X-Tenant-ID",
 *     in="header",
 *     required=false,
 *     description="Tenant UUID. Required when not using subdomain or JWT claim for tenant resolution.",
 *     @OA\Schema(type="string", format="uuid", example="550e8400-e29b-41d4-a716-446655440000")
 * )
 *
 * @OA\Parameter(
 *     parameter="XRequestID",
 *     name="X-Request-ID",
 *     in="header",
 *     required=false,
 *     description="Optional client-supplied request ID for tracing. If omitted, the server generates one. Echoed in the response X-Request-ID header.",
 *     @OA\Schema(type="string", format="uuid", example="123e4567-e89b-12d3-a456-426614174000")
 * )
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Common Response Headers
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * @OA\Header(
 *     header="X-Request-ID",
 *     description="UUID v4 request identifier for distributed tracing. Present on every response.",
 *     @OA\Schema(type="string", format="uuid", example="123e4567-e89b-12d3-a456-426614174000")
 * )
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Reusable Response Schemas
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * @OA\Schema(
 *     schema="SuccessResponse",
 *     type="object",
 *     description="Standard success response envelope.",
 *     @OA\Property(property="success", type="boolean", example=true),
 *     @OA\Property(property="data", nullable=true),
 *     @OA\Property(property="message", type="string", example="Success."),
 *     @OA\Property(property="errors", nullable=true, example=null),
 *     @OA\Property(property="meta", nullable=true, example=null)
 * )
 *
 * @OA\Schema(
 *     schema="ErrorResponse",
 *     type="object",
 *     description="Standard error response envelope.",
 *     @OA\Property(property="success", type="boolean", example=false),
 *     @OA\Property(property="data", nullable=true, example=null),
 *     @OA\Property(property="message", type="string", example="An error occurred."),
 *     @OA\Property(
 *         property="errors",
 *         nullable=true,
 *         type="object",
 *         example={"field": {"Validation message."}}
 *     ),
 *     @OA\Property(property="meta", nullable=true, example=null)
 * )
 *
 * @OA\Schema(
 *     schema="PaginationMeta",
 *     type="object",
 *     description="Pagination metadata included in list responses.",
 *     @OA\Property(property="current_page", type="integer", example=1),
 *     @OA\Property(property="per_page", type="integer", example=20),
 *     @OA\Property(property="total", type="integer", example=100),
 *     @OA\Property(property="last_page", type="integer", example=5),
 *     @OA\Property(property="from", type="integer", nullable=true, example=1),
 *     @OA\Property(property="to", type="integer", nullable=true, example=20)
 * )
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Entity Schemas
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * @OA\Schema(
 *     schema="UserResource",
 *     type="object",
 *     description="Authenticated user or user record.",
 *     @OA\Property(property="id", type="string", format="uuid", example="550e8400-e29b-41d4-a716-446655440000"),
 *     @OA\Property(property="tenant_id", type="string", format="uuid"),
 *     @OA\Property(property="name", type="string", example="Jane Doe"),
 *     @OA\Property(property="email", type="string", format="email", example="jane.doe@acme.com"),
 *     @OA\Property(property="status", type="string", enum={"active","inactive","locked"}, example="active"),
 *     @OA\Property(property="phone", type="string", nullable=true, example="+1-555-0100"),
 *     @OA\Property(property="avatar", type="string", nullable=true, example="https://cdn.example.com/avatars/uuid.jpg"),
 *     @OA\Property(property="department_id", type="string", format="uuid", nullable=true),
 *     @OA\Property(property="roles", type="array", @OA\Items(type="string"), example={"Procurement_Officer"}),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time")
 * )
 *
 * @OA\Schema(
 *     schema="DepartmentResource",
 *     type="object",
 *     @OA\Property(property="id", type="string", format="uuid"),
 *     @OA\Property(property="tenant_id", type="string", format="uuid"),
 *     @OA\Property(property="name", type="string", example="Finance"),
 *     @OA\Property(property="code", type="string", example="FIN"),
 *     @OA\Property(property="parent_id", type="string", format="uuid", nullable=true),
 *     @OA\Property(property="status", type="string", enum={"active","inactive"}, example="active"),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time")
 * )
 *
 * @OA\Schema(
 *     schema="BudgetResource",
 *     type="object",
 *     @OA\Property(property="id", type="string", format="uuid"),
 *     @OA\Property(property="tenant_id", type="string", format="uuid"),
 *     @OA\Property(property="department_id", type="string", format="uuid"),
 *     @OA\Property(property="fiscal_year", type="integer", example=2025),
 *     @OA\Property(property="currency", type="string", example="USD"),
 *     @OA\Property(property="total_amount", type="string", example="500000.00"),
 *     @OA\Property(property="encumbered_amount", type="string", example="120000.00"),
 *     @OA\Property(property="spent_amount", type="string", example="80000.00"),
 *     @OA\Property(property="available_amount", type="string", example="300000.00"),
 *     @OA\Property(property="utilization_percent", type="string", example="40.00")
 * )
 *
 * @OA\Schema(
 *     schema="PurchaseRequestResource",
 *     type="object",
 *     @OA\Property(property="id", type="string", format="uuid"),
 *     @OA\Property(property="pr_number", type="string", example="PR-ACME-2025-0001"),
 *     @OA\Property(property="tenant_id", type="string", format="uuid"),
 *     @OA\Property(property="department_id", type="string", format="uuid"),
 *     @OA\Property(property="submitted_by", type="string", format="uuid"),
 *     @OA\Property(property="title", type="string", example="Office Supplies Q1"),
 *     @OA\Property(property="description", type="string", nullable=true),
 *     @OA\Property(property="status", type="string", enum={"draft","pending_approval","approved","rejected","revision_required","cancelled"}, example="draft"),
 *     @OA\Property(property="estimated_total", type="string", example="4500.00"),
 *     @OA\Property(property="currency", type="string", example="USD"),
 *     @OA\Property(property="required_date", type="string", format="date", nullable=true),
 *     @OA\Property(property="submitted_at", type="string", format="date-time", nullable=true),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time")
 * )
 *
 * @OA\Schema(
 *     schema="PurchaseRequestItem",
 *     type="object",
 *     @OA\Property(property="id", type="string", format="uuid"),
 *     @OA\Property(property="description", type="string", example="A4 Paper Reams"),
 *     @OA\Property(property="quantity", type="string", example="100.00"),
 *     @OA\Property(property="unit_of_measure", type="string", example="reams"),
 *     @OA\Property(property="estimated_unit_price", type="string", example="4.50"),
 *     @OA\Property(property="budget_code", type="string", nullable=true, example="STATIONERY-2025")
 * )
 *
 * @OA\Schema(
 *     schema="SupplierResource",
 *     type="object",
 *     @OA\Property(property="id", type="string", format="uuid"),
 *     @OA\Property(property="tenant_id", type="string", format="uuid"),
 *     @OA\Property(property="organization_name", type="string", example="Acme Supplies Ltd."),
 *     @OA\Property(property="contact_name", type="string", example="John Smith"),
 *     @OA\Property(property="contact_email", type="string", format="email"),
 *     @OA\Property(property="contact_phone", type="string", nullable=true),
 *     @OA\Property(property="business_category", type="string", example="Office Supplies"),
 *     @OA\Property(property="status", type="string", enum={"pending_verification","active","blacklisted","inactive"}),
 *     @OA\Property(property="on_time_delivery_rate", type="string", example="95.50"),
 *     @OA\Property(property="quality_acceptance_rate", type="string", example="98.20")
 * )
 *
 * @OA\Schema(
 *     schema="TenderResource",
 *     type="object",
 *     @OA\Property(property="id", type="string", format="uuid"),
 *     @OA\Property(property="tenant_id", type="string", format="uuid"),
 *     @OA\Property(property="reference_number", type="string", example="TND-ACME-2025-0001"),
 *     @OA\Property(property="title", type="string"),
 *     @OA\Property(property="description", type="string"),
 *     @OA\Property(property="category", type="string"),
 *     @OA\Property(property="tender_type", type="string", enum={"open","restricted","single_source"}),
 *     @OA\Property(property="estimated_value", type="string", example="250000.00"),
 *     @OA\Property(property="submission_deadline", type="string", format="date-time"),
 *     @OA\Property(property="status", type="string", enum={"draft","published","closed","awarded","cancelled"}),
 *     @OA\Property(property="created_at", type="string", format="date-time")
 * )
 *
 * @OA\Schema(
 *     schema="BidResource",
 *     type="object",
 *     @OA\Property(property="id", type="string", format="uuid"),
 *     @OA\Property(property="tender_id", type="string", format="uuid"),
 *     @OA\Property(property="supplier_id", type="string", format="uuid"),
 *     @OA\Property(property="total_amount", type="string", example="195000.00"),
 *     @OA\Property(property="currency", type="string", example="USD"),
 *     @OA\Property(property="delivery_days", type="integer", example=30),
 *     @OA\Property(property="technical_notes", type="string", nullable=true),
 *     @OA\Property(property="status", type="string", enum={"draft","submitted","under_evaluation","won","lost","disqualified"}),
 *     @OA\Property(property="weighted_score", type="string", nullable=true, example="87.50"),
 *     @OA\Property(property="submitted_at", type="string", format="date-time", nullable=true)
 * )
 *
 * @OA\Schema(
 *     schema="PurchaseOrderResource",
 *     type="object",
 *     @OA\Property(property="id", type="string", format="uuid"),
 *     @OA\Property(property="po_number", type="string", example="PO-ACME-2025-0001"),
 *     @OA\Property(property="supplier_id", type="string", format="uuid"),
 *     @OA\Property(property="department_id", type="string", format="uuid"),
 *     @OA\Property(property="status", type="string", enum={"draft","issued","accepted","rejected","partially_received","fully_received","cancelled","overdue"}),
 *     @OA\Property(property="total_amount", type="string", example="195000.00"),
 *     @OA\Property(property="currency", type="string", example="USD"),
 *     @OA\Property(property="delivery_address", type="string"),
 *     @OA\Property(property="required_delivery_date", type="string", format="date"),
 *     @OA\Property(property="issued_at", type="string", format="date-time", nullable=true),
 *     @OA\Property(property="created_at", type="string", format="date-time")
 * )
 *
 * @OA\Schema(
 *     schema="ContractResource",
 *     type="object",
 *     @OA\Property(property="id", type="string", format="uuid"),
 *     @OA\Property(property="contract_number", type="string", example="CTR-ACME-2025-0001"),
 *     @OA\Property(property="supplier_id", type="string", format="uuid"),
 *     @OA\Property(property="title", type="string"),
 *     @OA\Property(property="scope", type="string"),
 *     @OA\Property(property="total_value", type="string", example="500000.00"),
 *     @OA\Property(property="consumed_value", type="string", example="125000.00"),
 *     @OA\Property(property="currency", type="string", example="USD"),
 *     @OA\Property(property="start_date", type="string", format="date"),
 *     @OA\Property(property="end_date", type="string", format="date"),
 *     @OA\Property(property="payment_terms", type="string"),
 *     @OA\Property(property="status", type="string", enum={"draft","pending_bond","active","expired","terminated","renewed"})
 * )
 *
 * @OA\Schema(
 *     schema="InvoiceResource",
 *     type="object",
 *     @OA\Property(property="id", type="string", format="uuid"),
 *     @OA\Property(property="invoice_number", type="string", example="INV-2025-001"),
 *     @OA\Property(property="supplier_id", type="string", format="uuid"),
 *     @OA\Property(property="purchase_order_id", type="string", format="uuid", nullable=true),
 *     @OA\Property(property="contract_id", type="string", format="uuid", nullable=true),
 *     @OA\Property(property="total_amount", type="string", example="48750.00"),
 *     @OA\Property(property="paid_amount", type="string", example="0.00"),
 *     @OA\Property(property="currency", type="string", example="USD"),
 *     @OA\Property(property="status", type="string", enum={"submitted","under_review","approved","rejected","partially_paid","paid"}),
 *     @OA\Property(property="due_date", type="string", format="date", nullable=true),
 *     @OA\Property(property="created_at", type="string", format="date-time")
 * )
 *
 * @OA\Schema(
 *     schema="PaymentResource",
 *     type="object",
 *     @OA\Property(property="id", type="string", format="uuid"),
 *     @OA\Property(property="invoice_id", type="string", format="uuid"),
 *     @OA\Property(property="amount", type="string", example="48750.00"),
 *     @OA\Property(property="currency", type="string", example="USD"),
 *     @OA\Property(property="payment_method", type="string", example="bank_transfer"),
 *     @OA\Property(property="payment_reference", type="string", nullable=true, example="TXN-20250101-001"),
 *     @OA\Property(property="payment_date", type="string", format="date", nullable=true),
 *     @OA\Property(property="due_date", type="string", format="date", nullable=true),
 *     @OA\Property(property="status", type="string", enum={"pending","processed","failed"}),
 *     @OA\Property(property="created_at", type="string", format="date-time")
 * )
 *
 * @OA\Schema(
 *     schema="NotificationResource",
 *     type="object",
 *     @OA\Property(property="id", type="string", format="uuid"),
 *     @OA\Property(property="type", type="string", example="PurchaseRequestSubmitted"),
 *     @OA\Property(property="title", type="string", example="New Purchase Request"),
 *     @OA\Property(property="message", type="string"),
 *     @OA\Property(property="is_read", type="boolean", example=false),
 *     @OA\Property(property="data", type="object", nullable=true),
 *     @OA\Property(property="created_at", type="string", format="date-time")
 * )
 *
 * @OA\Schema(
 *     schema="AuditLogResource",
 *     type="object",
 *     @OA\Property(property="id", type="string", format="uuid"),
 *     @OA\Property(property="tenant_id", type="string", format="uuid"),
 *     @OA\Property(property="user_id", type="string", format="uuid", nullable=true),
 *     @OA\Property(property="user_role", type="string", nullable=true, example="Procurement_Officer"),
 *     @OA\Property(property="action", type="string", example="purchase_request.submitted"),
 *     @OA\Property(property="entity_type", type="string", example="PurchaseRequest"),
 *     @OA\Property(property="entity_id", type="string", format="uuid"),
 *     @OA\Property(property="before", type="object", nullable=true),
 *     @OA\Property(property="after", type="object", nullable=true),
 *     @OA\Property(property="ip_address", type="string", example="192.168.1.1"),
 *     @OA\Property(property="request_id", type="string", format="uuid"),
 *     @OA\Property(property="created_at", type="string", format="date-time")
 * )
 *
 * @OA\Schema(
 *     schema="GoodsReceiptResource",
 *     type="object",
 *     @OA\Property(property="id", type="string", format="uuid"),
 *     @OA\Property(property="grn_number", type="string", example="GRN-ACME-2025-0001"),
 *     @OA\Property(property="purchase_order_id", type="string", format="uuid"),
 *     @OA\Property(property="warehouse_id", type="string", format="uuid"),
 *     @OA\Property(property="delivery_note_number", type="string"),
 *     @OA\Property(property="status", type="string", enum={"draft","under_inspection","accepted","partially_accepted","rejected"}),
 *     @OA\Property(property="received_by", type="string", format="uuid"),
 *     @OA\Property(property="received_at", type="string", format="date-time")
 * )
 *
 * @OA\Schema(
 *     schema="InventoryResource",
 *     type="object",
 *     @OA\Property(property="id", type="string", format="uuid"),
 *     @OA\Property(property="warehouse_id", type="string", format="uuid"),
 *     @OA\Property(property="item_code", type="string", example="PAPER-A4-80GSM"),
 *     @OA\Property(property="item_name", type="string", example="A4 Paper 80gsm"),
 *     @OA\Property(property="category", type="string", example="Stationery"),
 *     @OA\Property(property="unit_of_measure", type="string", example="reams"),
 *     @OA\Property(property="current_stock", type="string", example="500.00"),
 *     @OA\Property(property="reorder_threshold", type="string", example="100.00"),
 *     @OA\Property(property="unit_cost", type="string", example="4.50")
 * )
 *
 * @OA\Schema(
 *     schema="ApprovalWorkflowResource",
 *     type="object",
 *     @OA\Property(property="id", type="string", format="uuid"),
 *     @OA\Property(property="name", type="string", example="Standard PR Approval"),
 *     @OA\Property(property="document_type", type="string", enum={"purchase_request","tender","purchase_order","contract","invoice"}),
 *     @OA\Property(property="department_id", type="string", format="uuid", nullable=true),
 *     @OA\Property(property="is_active", type="boolean", example=true),
 *     @OA\Property(property="levels", type="array", @OA\Items(ref="#/components/schemas/ApprovalWorkflowLevel"))
 * )
 *
 * @OA\Schema(
 *     schema="ApprovalWorkflowLevel",
 *     type="object",
 *     @OA\Property(property="id", type="string", format="uuid"),
 *     @OA\Property(property="level_order", type="integer", example=1),
 *     @OA\Property(property="approver_type", type="string", enum={"role","user"}),
 *     @OA\Property(property="approver_role", type="string", nullable=true, example="Finance_Officer"),
 *     @OA\Property(property="approver_user_id", type="string", format="uuid", nullable=true),
 *     @OA\Property(property="is_parallel", type="boolean", example=false),
 *     @OA\Property(property="escalation_hours", type="integer", example=48)
 * )
 *
 * @OA\Schema(
 *     schema="TenantResource",
 *     type="object",
 *     @OA\Property(property="id", type="string", format="uuid"),
 *     @OA\Property(property="name", type="string", example="Acme Corporation"),
 *     @OA\Property(property="subdomain", type="string", example="acme"),
 *     @OA\Property(property="admin_email", type="string", format="email"),
 *     @OA\Property(property="status", type="string", enum={"active","suspended","deactivated"}),
 *     @OA\Property(property="tenant_code", type="string", example="ACME"),
 *     @OA\Property(property="created_at", type="string", format="date-time")
 * )
 *
 * @OA\Schema(
 *     schema="BidEvaluationCriteriaResource",
 *     type="object",
 *     @OA\Property(property="id", type="string", format="uuid"),
 *     @OA\Property(property="tender_id", type="string", format="uuid"),
 *     @OA\Property(property="name", type="string", example="Technical Compliance"),
 *     @OA\Property(property="weight", type="string", example="40.00"),
 *     @OA\Property(property="max_score", type="string", example="100.00")
 * )
 *
 * @OA\Schema(
 *     schema="ApprovalResource",
 *     type="object",
 *     @OA\Property(property="id", type="string", format="uuid"),
 *     @OA\Property(property="document_type", type="string"),
 *     @OA\Property(property="document_id", type="string", format="uuid"),
 *     @OA\Property(property="approver_id", type="string", format="uuid"),
 *     @OA\Property(property="action", type="string", enum={"pending","approved","rejected","returned"}),
 *     @OA\Property(property="comment", type="string", nullable=true),
 *     @OA\Property(property="acted_at", type="string", format="date-time", nullable=true)
 * )
 */
class OpenApiSpec
{
    // This class exists solely to hold OpenAPI annotations. No runtime logic.
}
