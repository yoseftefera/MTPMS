/**
 * Reporting & Analytics domain types.
 *
 * Validates: Requirements 16.1, 16.2, 16.3, 16.4, 16.5, 16.6
 */

// ─── Dashboard KPIs ───────────────────────────────────────────────────────────

export interface DashboardKPIs {
  /** PRs grouped by status */
  pr_by_status: Record<string, number>;
  /** Number of currently published/open tenders */
  active_tenders: number;
  /** Percentage 0-100 */
  po_fulfillment_rate: string;
  /** Percentage 0-100 (weighted average across all departments) */
  budget_utilization_percentage: string;
  /** Approvals with action = 'pending' assigned to current user */
  pending_approvals_count: number;
  /** POs past required_delivery_date without accepted GRN */
  overdue_deliveries_count: number;
}

// ─── Procurement Timeline ─────────────────────────────────────────────────────

export interface ProcurementTimelineData {
  /** Average days from PR submission to PO issuance */
  avg_pr_to_po_days: string;
  monthly_trend: MonthlyTimelineItem[];
}

export interface MonthlyTimelineItem {
  month: string; // e.g. "2025-01"
  avg_days: string;
  pr_count: number;
}

// ─── Spending Analytics ───────────────────────────────────────────────────────

export interface SpendingAnalyticsData {
  total_spend: string;
  currency: string;
  by_department: SpendByDimension[];
  by_category: SpendByDimension[];
  by_supplier: SpendByDimension[];
  monthly_trend: MonthlySpendItem[];
}

export interface SpendByDimension {
  label: string; // department name, category, or supplier name
  amount: string;
  percentage: string;
}

export interface MonthlySpendItem {
  month: string;
  amount: string;
  previous_amount: string; // same month previous year / previous period
}

// ─── Supplier Performance ─────────────────────────────────────────────────────

export interface SupplierPerformanceData {
  suppliers: SupplierPerformanceItem[];
}

export interface SupplierPerformanceItem {
  supplier_id: string;
  supplier_name: string;
  on_time_delivery_rate: string;
  quality_acceptance_rate: string;
  total_po_value: string;
  po_count: number;
  avg_bid_competitiveness: string;
}

// ─── Tender Statistics ────────────────────────────────────────────────────────

export interface TenderStatisticsData {
  total_tenders: number;
  by_status: Record<string, number>;
  by_category: TenderByCategory[];
  avg_bids_per_tender: string;
  avg_evaluation_days: string;
  monthly_trend: MonthlyTenderItem[];
}

export interface TenderByCategory {
  category: string;
  count: number;
  total_estimated_value: string;
}

export interface MonthlyTenderItem {
  month: string;
  published: number;
  awarded: number;
  cancelled: number;
}

// ─── Financial Summary ────────────────────────────────────────────────────────

export interface FinancialSummaryData {
  currency: string;
  total_invoiced: string;
  total_paid: string;
  total_outstanding: string;
  budget_variance: string; // positive = under budget, negative = over
  by_department: FinancialByDepartment[];
  payment_trend: MonthlyPaymentItem[];
}

export interface FinancialByDepartment {
  department_id: string;
  department_name: string;
  invoiced: string;
  paid: string;
  outstanding: string;
  budget_allocated: string;
  budget_variance: string;
}

export interface MonthlyPaymentItem {
  month: string;
  invoiced: string;
  paid: string;
}

// ─── Report filter params ─────────────────────────────────────────────────────

export interface ReportFilters {
  date_from?: string;
  date_to?: string;
  department_id?: string;
  category?: string;
  status?: string;
  supplier_id?: string;
  page?: number;
  per_page?: number;
}
