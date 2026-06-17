/**
 * Goods Receipt and Inventory domain types for the Procurement Management Platform.
 *
 * Mirrors the backend Eloquent models and API resource shapes.
 *
 * Validates: Requirements 12.1, 12.8, 22.6
 */

import type {
  GoodsReceiptStatus,
  GoodsReceipt,
  PurchaseOrder,
  User,
} from "./models.types"

// ─── Re-exports ───────────────────────────────────────────────────────────────

export type { GoodsReceiptStatus, GoodsReceipt }

// ─── GRN item ─────────────────────────────────────────────────────────────────

export type GRNItemStatus =
  | "pending"
  | "accepted"
  | "rejected"
  | "partially_accepted"

export interface GoodsReceiptItem {
  id: string
  tenant_id: string
  goods_receipt_id: string
  purchase_order_item_id: string | null
  description: string
  ordered_quantity: string
  received_quantity: string
  accepted_quantity: string
  rejected_quantity: string
  unit_of_measure: string
  status: GRNItemStatus
  rejection_reason: string | null
  created_at: string
  updated_at: string
}

// ─── GRN detail (includes relations) ─────────────────────────────────────────

export interface GoodsReceiptDetail extends GoodsReceipt {
  purchase_order?: PurchaseOrder & {
    po_number: string
    supplier?: { id: string; organization_name: string }
  }
  items: GoodsReceiptItem[]
  receiver?: User
  committee_members?: User[]
}

// ─── Filters ──────────────────────────────────────────────────────────────────

export interface GRNFilters {
  page?: number
  per_page?: number
  status?: GRNFilterStatus
  purchase_order_id?: string
}

export type GRNFilterStatus =
  | ""
  | "pending_inspection"
  | "under_inspection"
  | "accepted"
  | "partially_accepted"
  | "rejected"

// ─── Create GRN payload ───────────────────────────────────────────────────────

export interface CreateGRNItemData {
  purchase_order_item_id: string
  description: string
  received_quantity: number
}

export interface CreateGRNData {
  purchase_order_id: string
  warehouse_id: string
  delivery_note_number: string
  items: CreateGRNItemData[]
}

// ─── Assign committee payload ─────────────────────────────────────────────────

export interface AssignCommitteeData {
  committee_user_ids: string[]
}

// ─── Inspection result payload ────────────────────────────────────────────────

export interface InspectionResultItem {
  grn_item_id: string
  accepted: boolean
  notes?: string
}

export interface InspectionResultData {
  inspector_id: string
  results: InspectionResultItem[]
}

// ─── PO lookup (for create GRN form) ─────────────────────────────────────────

export interface POItemLookup {
  id: string
  description: string
  quantity: string
  received_quantity: string
  unit_of_measure: string
  outstanding_quantity: number
}

export interface POLookupResult {
  id: string
  po_number: string
  supplier?: { id: string; organization_name: string }
  items: POItemLookup[]
}

// ─── Inventory ────────────────────────────────────────────────────────────────

export type StockLevel = "in_stock" | "low_stock" | "out_of_stock"

export interface InventoryItem {
  id: string
  tenant_id: string
  item_code: string
  item_name: string
  category: string
  warehouse_id: string
  warehouse?: { id: string; name: string }
  current_stock: string
  reorder_threshold: string
  unit_of_measure: string
  stock_level?: StockLevel
  created_at: string
  updated_at: string
}

// ─── Inventory filters ────────────────────────────────────────────────────────

export interface InventoryFilters {
  page?: number
  per_page?: number
  warehouse_id?: string
  item_code?: string
  category?: string
  stock_level?: string
  below_reorder?: boolean
}
