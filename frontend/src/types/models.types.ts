/**
 * Domain model types for the Procurement Management Platform.
 * These mirror the backend Eloquent models and API resource shapes.
 */

// ─── Tenant ──────────────────────────────────────────────────────────────────

export interface Tenant {
  id: string;
  name: string;
  subdomain: string;
  admin_email: string;
  status: 'active' | 'suspended' | 'deactivated';
  tenant_code: string;
  settings: Record<string, unknown> | null;
  created_at: string;
  updated_at: string;
}

// ─── User ─────────────────────────────────────────────────────────────────────

export interface User {
  id: string;
  tenant_id: string;
  name: string;
  email: string;
  department_id: string | null;
  status: 'active' | 'inactive' | 'locked';
  failed_login_attempts: number;
  avatar: string | null;
  phone: string | null;
  email_verified_at: string | null;
  roles: string[];
  permissions: string[];
  created_at: string;
  updated_at: string;
}

// ─── Department ───────────────────────────────────────────────────────────────

export interface Department {
  id: string;
  tenant_id: string;
  name: string;
  code: string;
  parent_id: string | null;
  status: 'active' | 'inactive';
  created_at: string;
  updated_at: string;
}

// ─── Budget ───────────────────────────────────────────────────────────────────

export interface Budget {
  id: string;
  tenant_id: string;
  department_id: string;
  department?: Department;
  fiscal_year: number;
  currency: string;
  total_amount: string;
  encumbered_amount: string;
  spent_amount: string;
  created_by: string;
  created_at: string;
  updated_at: string;
}

// ─── Purchase Request ─────────────────────────────────────────────────────────

export type PurchaseRequestStatus =
  | 'draft'
  | 'pending_approval'
  | 'approved'
  | 'rejected'
  | 'revision_required'
  | 'cancelled';

export interface PurchaseRequestItem {
  id: string;
  tenant_id: string;
  purchase_request_id: string;
  description: string;
  quantity: string;
  unit_of_measure: string;
  estimated_unit_price: string;
  budget_code: string | null;
  created_at: string;
  updated_at: string;
}

export interface PurchaseRequestHistory {
  id: string;
  tenant_id: string;
  purchase_request_id: string;
  action: string;
  from_status: string | null;
  to_status: string | null;
  comment: string | null;
  performed_by: string;
  performer?: User;
  created_at: string;
}

export interface PurchaseRequest {
  id: string;
  tenant_id: string;
  pr_number: string;
  department_id: string;
  department?: Department;
  submitted_by: string;
  submitter?: User;
  status: PurchaseRequestStatus;
  title: string;
  description: string | null;
  estimated_total: string;
  currency: string;
  required_date: string | null;
  submitted_at: string | null;
  items?: PurchaseRequestItem[];
  history?: PurchaseRequestHistory[];
  created_at: string;
  updated_at: string;
}

// ─── Approval ─────────────────────────────────────────────────────────────────

export interface ApprovalWorkflow {
  id: string;
  tenant_id: string;
  name: string;
  document_type: 'purchase_request' | 'tender' | 'purchase_order' | 'contract' | 'invoice';
  department_id: string | null;
  is_active: boolean;
  levels?: ApprovalWorkflowLevel[];
  created_at: string;
  updated_at: string;
}

export interface ApprovalWorkflowLevel {
  id: string;
  tenant_id: string;
  workflow_id: string;
  level_order: number;
  approver_type: 'role' | 'user';
  approver_role: string | null;
  approver_user_id: string | null;
  approver_user?: User;
  is_parallel: boolean;
  escalation_hours: number;
  created_at: string;
  updated_at: string;
}

export interface Approval {
  id: string;
  tenant_id: string;
  workflow_id: string;
  level_id: string;
  document_type: string;
  document_id: string;
  approver_id: string;
  approver?: User;
  action: 'pending' | 'approved' | 'rejected' | 'returned';
  comment: string | null;
  acted_at: string | null;
  created_at: string;
  updated_at: string;
}

// ─── Supplier ─────────────────────────────────────────────────────────────────

export type SupplierStatus = 'pending_verification' | 'active' | 'blacklisted' | 'inactive';

export interface Supplier {
  id: string;
  tenant_id: string;
  user_id: string | null;
  organization_name: string;
  contact_name: string;
  contact_email: string;
  contact_phone: string | null;
  business_category: string;
  status: SupplierStatus;
  blacklist_reason: string | null;
  blacklisted_by: string | null;
  blacklisted_at: string | null;
  on_time_delivery_rate: string;
  quality_acceptance_rate: string;
  documents?: SupplierDocument[];
  created_at: string;
  updated_at: string;
}

export interface SupplierDocument {
  id: string;
  tenant_id: string;
  supplier_id: string;
  document_type: 'tin_certificate' | 'vat_certificate' | 'business_license' | 'performance_bond' | 'other';
  file_path: string;
  file_name: string;
  expires_at: string | null;
  version: number;
  uploaded_by: string;
  created_at: string;
}

// ─── Tender ───────────────────────────────────────────────────────────────────

export type TenderStatus = 'draft' | 'published' | 'closed' | 'awarded' | 'cancelled';

export interface Tender {
  id: string;
  tenant_id: string;
  reference_number: string;
  title: string;
  description: string;
  category: string;
  tender_type: 'open' | 'restricted' | 'single_source';
  estimated_value: string;
  submission_deadline: string;
  status: TenderStatus;
  created_by: string;
  published_at: string | null;
  cancellation_reason: string | null;
  bids?: Bid[];
  created_at: string;
  updated_at: string;
}

// ─── Bid ──────────────────────────────────────────────────────────────────────

export type BidStatus = 'draft' | 'submitted' | 'under_evaluation' | 'won' | 'lost' | 'disqualified';

export interface Bid {
  id: string;
  tenant_id: string;
  tender_id: string;
  tender?: Tender;
  supplier_id: string;
  supplier?: Supplier;
  total_amount: string;
  currency: string;
  delivery_days: number;
  technical_notes: string | null;
  status: BidStatus;
  submitted_at: string | null;
  weighted_score: string | null;
  created_at: string;
  updated_at: string;
}

// ─── Purchase Order ───────────────────────────────────────────────────────────

export type PurchaseOrderStatus =
  | 'draft'
  | 'issued'
  | 'accepted'
  | 'rejected'
  | 'partially_received'
  | 'fully_received'
  | 'cancelled'
  | 'overdue';

export interface PurchaseOrderItem {
  id: string;
  tenant_id: string;
  purchase_order_id: string;
  description: string;
  quantity: string;
  received_quantity: string;
  unit_of_measure: string;
  unit_price: string;
  total_price: string;
  created_at: string;
  updated_at: string;
}

export interface PurchaseOrder {
  id: string;
  tenant_id: string;
  po_number: string;
  purchase_request_id: string | null;
  bid_id: string | null;
  supplier_id: string;
  supplier?: Supplier;
  department_id: string;
  department?: Department;
  status: PurchaseOrderStatus;
  total_amount: string;
  currency: string;
  delivery_address: string;
  required_delivery_date: string;
  issued_at: string | null;
  accepted_at: string | null;
  created_by: string;
  items?: PurchaseOrderItem[];
  created_at: string;
  updated_at: string;
}

// ─── Contract ─────────────────────────────────────────────────────────────────

export type ContractStatus = 'draft' | 'pending_bond' | 'active' | 'expired' | 'terminated' | 'renewed';

export interface Contract {
  id: string;
  tenant_id: string;
  contract_number: string;
  purchase_order_id: string | null;
  tender_id: string | null;
  supplier_id: string;
  supplier?: Supplier;
  title: string;
  scope: string;
  total_value: string;
  consumed_value: string;
  currency: string;
  start_date: string;
  end_date: string;
  payment_terms: string;
  status: ContractStatus;
  termination_reason: string | null;
  created_by: string;
  created_at: string;
  updated_at: string;
}

// ─── Goods Receipt ────────────────────────────────────────────────────────────

export type GoodsReceiptStatus = 'pending_inspection' | 'under_inspection' | 'accepted' | 'partially_accepted' | 'rejected';

export interface GoodsReceipt {
  id: string;
  tenant_id: string;
  grn_number: string;
  purchase_order_id: string;
  purchase_order?: PurchaseOrder;
  warehouse_id: string;
  delivery_note_number: string;
  status: GoodsReceiptStatus;
  received_by: string;
  received_at: string;
  created_at: string;
  updated_at: string;
}

// ─── Invoice ──────────────────────────────────────────────────────────────────

export type InvoiceStatus = 'pending_approval' | 'submitted' | 'under_review' | 'approved' | 'rejected' | 'partially_paid' | 'paid';

export interface Invoice {
  id: string;
  tenant_id: string;
  invoice_number: string;
  supplier_id: string;
  supplier?: Supplier;
  purchase_order_id: string | null;
  contract_id: string | null;
  total_amount: string;
  paid_amount: string;
  currency: string;
  due_date: string;
  status: InvoiceStatus;
  rejection_reason: string | null;
  created_at: string;
  updated_at: string;
}

// ─── Payment ──────────────────────────────────────────────────────────────────

export interface Payment {
  id: string;
  tenant_id: string;
  invoice_id: string;
  invoice?: Invoice;
  amount: string;
  payment_method: string;
  payment_reference: string;
  scheduled_date: string;
  processed_at: string | null;
  status: 'scheduled' | 'processed' | 'failed';
  processed_by: string | null;
  created_at: string;
  updated_at: string;
}

// ─── Notification ─────────────────────────────────────────────────────────────

export interface Notification {
  id: string;
  tenant_id: string;
  user_id: string;
  event_type: string;
  title: string;
  message: string;
  data: Record<string, unknown> | null;
  is_read: boolean;
  read_at: string | null;
  created_at: string;
}

// ─── Audit Log ────────────────────────────────────────────────────────────────

export interface AuditLog {
  id: string;
  tenant_id: string | null;
  user_id: string | null;
  user_role: string | null;
  action_type: string;
  entity_type: string;
  entity_id: string | null;
  before_state: Record<string, unknown> | null;
  after_state: Record<string, unknown> | null;
  ip_address: string;
  request_id: string | null;
  created_at: string;
}
