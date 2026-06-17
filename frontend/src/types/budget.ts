/**
 * Budget domain types for the Procurement Management Platform.
 *
 * Validates: Requirements 13.1, 13.10
 */

// ─── Core models ──────────────────────────────────────────────────────────────

export interface Budget {
  id: string;
  tenant_id: string;
  department_id: string;
  department_name: string;
  fiscal_year: number;
  currency: string;
  /** Decimal string, e.g. "1000.00" */
  total_amount: string;
  /** Decimal string – amounts committed to pending POs */
  encumbered_amount: string;
  /** Decimal string – amounts fully expended */
  spent_amount: string;
  /** Decimal string – total_amount − encumbered − spent */
  available_amount: string;
  /** Percentage string, e.g. "75.00" */
  utilization_percentage: string;
  created_by: string;
  created_at: string;
  updated_at?: string;
}

export interface BudgetTransaction {
  id: string;
  budget_id: string;
  /** 'encumber' | 'release_encumbrance' | 'expenditure' | 'transfer_in' | 'transfer_out' */
  type: string;
  /** Decimal string */
  amount: string;
  reference_type: string;
  reference_id: string;
  created_by: string;
  created_at: string;
}

export interface UtilizationReportItem {
  department_id: string;
  department_name: string;
  fiscal_year: number;
  currency: string;
  total_amount: string;
  encumbered_amount: string;
  spent_amount: string;
  available_amount: string;
  utilization_percentage: string;
}

export interface UtilizationReport {
  fiscal_year: number;
  items: UtilizationReportItem[];
  summary: {
    total_allocated: string;
    total_encumbered: string;
    total_spent: string;
    total_available: string;
  };
}

// ─── Form payloads ────────────────────────────────────────────────────────────

export interface CreateBudgetData {
  department_id: string;
  fiscal_year: number;
  total_amount: string;
  currency: string;
}

export interface UpdateBudgetData {
  total_amount?: string;
  currency?: string;
}

export interface TransferBudgetData {
  from_budget_id: string;
  to_budget_id: string;
  /** Positive decimal string */
  amount: string;
  note?: string;
}

// ─── Query params ─────────────────────────────────────────────────────────────

export interface BudgetFilters {
  fiscal_year?: number;
  department_id?: string;
  page?: number;
  per_page?: number;
}
