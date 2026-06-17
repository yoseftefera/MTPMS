/**
 * Purchase Request domain types for the Procurement Management Platform.
 *
 * Validates: Requirements 5.2, 5.5, 5.7, 5.8
 */

// ─── Status ───────────────────────────────────────────────────────────────────

export type PRStatus =
  | "draft"
  | "pending_approval"
  | "approved"
  | "rejected"
  | "cancelled"
  | "revision_required"

// ─── Core models ──────────────────────────────────────────────────────────────

export interface PurchaseRequestItem {
  id: string
  description: string
  quantity: string | number
  unit_of_measure: string
  estimated_unit_price: string | number
  budget_code?: string | null
  /** Computed by the API: quantity × estimated_unit_price */
  line_total: string | number
}

export interface PurchaseRequestHistory {
  id: string
  action: string
  from_status: string | null
  to_status: string | null
  comment: string | null
  performed_by: string
  /** Embedded performer info when the API includes it */
  performer?: {
    id: string
    name: string
    email: string
  }
  created_at: string
}

export interface PRDocument {
  id: string
  file_name: string
  file_path: string
  file_size: number
  mime_type: string
  uploaded_by: string
  created_at: string
}

export interface PurchaseRequest {
  id: string
  pr_number: string
  title: string
  description: string | null
  status: PRStatus
  /** Human-readable status label from the API */
  status_label: string
  /** Decimal string, e.g. "1000.00" */
  estimated_total: string
  currency: string
  required_date: string | null
  submitted_at: string | null
  department_id: string
  department: {
    id: string
    name: string
    code: string
  }
  submitted_by: string
  submitter: {
    id: string
    name: string
    email: string
  }
  items: PurchaseRequestItem[]
  history: PurchaseRequestHistory[]
  documents?: PRDocument[]
  created_at: string
  updated_at: string
}

// ─── Form payloads ────────────────────────────────────────────────────────────

export interface CreatePRItemData {
  description: string
  quantity: string
  unit_of_measure: string
  estimated_unit_price: string
  budget_code?: string
}

export interface CreatePRData {
  title: string
  department_id: string
  description?: string
  required_date?: string
  currency: string
  items: CreatePRItemData[]
}

export interface UpdatePRData extends Partial<CreatePRData> {}

// ─── Query filters ────────────────────────────────────────────────────────────

export interface PRFilters {
  search?: string
  department_id?: string
  status?: PRStatus | ""
  date_from?: string
  date_to?: string
  page?: number
  per_page?: number
}
