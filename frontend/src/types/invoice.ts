/**
 * Invoice and Payment domain types for the Procurement Management Platform.
 * Mirrors the backend Eloquent models and API resource shapes.
 *
 * Validates: Requirements 14.1, 14.10, 22.6
 */

import type { InvoiceStatus } from "./models.types"

// ─── Re-exports ───────────────────────────────────────────────────────────────

export type { InvoiceStatus }

// ─── Invoice line item ────────────────────────────────────────────────────────

export interface InvoiceItem {
  id: string
  invoice_id: string
  description: string
  quantity: string
  unit_price: string
  total_price: string
  created_at: string
  updated_at: string
}

// ─── Approval history entry ───────────────────────────────────────────────────

export interface InvoiceApprovalEntry {
  id: string
  action: "pending" | "approved" | "rejected" | "returned"
  comment: string | null
  approver: {
    id: string
    name: string
    email: string
  } | null
  acted_at: string | null
  created_at: string
}

// ─── Invoice detail ───────────────────────────────────────────────────────────

export interface InvoiceDetail {
  id: string
  invoice_number: string
  status: InvoiceStatus
  supplier: {
    id: string
    organization_name: string
    contact_email: string
  }
  purchase_order: {
    id: string
    po_number: string
  } | null
  contract: {
    id: string
    contract_number: string
    title: string
  } | null
  invoice_date: string
  due_date: string
  total_amount: string
  paid_amount: string
  currency: string
  notes: string | null
  rejection_reason: string | null
  items: InvoiceItem[]
  approvals: InvoiceApprovalEntry[]
  created_at: string
  updated_at: string
}

// ─── Filters ──────────────────────────────────────────────────────────────────

export type InvoiceFilterStatus =
  | ""
  | "pending_approval"
  | "approved"
  | "rejected"
  | "partially_paid"
  | "paid"

export interface InvoiceFilters {
  page?: number
  per_page?: number
  status?: InvoiceFilterStatus
  supplier_id?: string
  date_from?: string
  date_to?: string
}

// ─── Create invoice payload ───────────────────────────────────────────────────

export interface CreateInvoiceData {
  purchase_order_id?: string | null
  contract_id?: string | null
  invoice_date: string
  due_date: string
  currency: string
  total_amount: number
  notes?: string | null
}

// ─── Payment ──────────────────────────────────────────────────────────────────

export type PaymentStatus = "scheduled" | "processed" | "failed"

export type PaymentMethod =
  | "bank_transfer"
  | "cheque"
  | "cash"
  | "mobile_money"
  | "credit_card"

export interface PaymentDetail {
  id: string
  invoice_id: string
  invoice: {
    id: string
    invoice_number: string
    total_amount: string
    paid_amount: string
    currency: string
    due_date: string
    supplier: {
      id: string
      organization_name: string
    }
  } | null
  amount: string
  amount_paid: string | null
  payment_method: PaymentMethod | string
  payment_reference: string | null
  scheduled_date: string
  processed_at: string | null
  status: PaymentStatus
  processed_by: string | null
  created_at: string
  updated_at: string
}

// ─── Payment schedule entry ───────────────────────────────────────────────────

export interface PaymentScheduleEntry {
  invoice_id: string
  invoice_number: string
  supplier_name: string
  amount_due: string
  currency: string
  due_date: string
  days_until_due: number
}

// ─── Payment filters ──────────────────────────────────────────────────────────

export interface PaymentFilters {
  page?: number
  per_page?: number
  status?: PaymentStatus | ""
  invoice_id?: string
}

// ─── Record payment payload ───────────────────────────────────────────────────

export interface RecordPaymentData {
  amount_paid: number
  payment_method: PaymentMethod
  payment_reference?: string | null
}
