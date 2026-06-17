/**
 * Purchase Order domain types for the Procurement Management Platform.
 *
 * Mirrors the backend Eloquent models and API resource shapes.
 *
 * Validates: Requirements 10.2, 10.9, 22.6
 */

import type { PurchaseOrderStatus, PurchaseOrderItem, PurchaseOrder } from "./models.types"

// ─── Re-exports ───────────────────────────────────────────────────────────────

export type { PurchaseOrderStatus, PurchaseOrderItem }

// ─── Extended PO detail (includes relations) ──────────────────────────────────

export interface PurchaseOrderDetail extends Omit<PurchaseOrder, "items"> {
  items: PurchaseOrderItem[]
  /** Status history / timeline entries */
  history?: POHistoryEntry[]
  /** True when the PO has been amended post-acceptance and the supplier hasn't ack'd yet */
  pending_supplier_acknowledgment?: boolean
  /** Human-readable status label (optional, from API) */
  status_label?: string
  /** Rejection reason provided by supplier */
  rejection_reason?: string | null
  /** Cancellation reason */
  cancellation_reason?: string | null
  /** Notes / internal remarks */
  notes?: string | null
  /** Creator user info */
  creator?: {
    id: string
    name: string
    email: string
  }
}

// ─── PO history / timeline entry ─────────────────────────────────────────────

export interface POHistoryEntry {
  id: string
  action: string
  from_status: string | null
  to_status: string | null
  comment: string | null
  performed_by: string
  performer?: {
    id: string
    name: string
    email: string
  }
  created_at: string
}

// ─── Filters ──────────────────────────────────────────────────────────────────

export interface POFilters {
  page?: number
  per_page?: number
  status?: POFilterStatus
  supplier_id?: string
  date_from?: string
  date_to?: string
  po_number?: string
}

export type POFilterStatus =
  | ""
  | "draft"
  | "issued"
  | "accepted"
  | "rejected"
  | "cancelled"
  | "overdue"
  | "partially_received"
  | "fully_received"

// ─── Create PO payload ────────────────────────────────────────────────────────

export interface CreatePOItemData {
  description: string
  quantity: string
  unit_of_measure: string
  unit_price: string
}

export interface CreatePOData {
  supplier_id: string
  department_id: string
  delivery_address: string
  required_delivery_date: string
  currency: string
  notes?: string
  items: CreatePOItemData[]
}

// ─── Amend PO payload ─────────────────────────────────────────────────────────

export interface AmendPOData {
  delivery_address?: string
  required_delivery_date?: string
  notes?: string
  items?: CreatePOItemData[]
}
