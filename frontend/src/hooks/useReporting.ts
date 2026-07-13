/**
 * TanStack Query hooks for Reporting & Analytics.
 *
 * Hooks:
 *   useDashboardKPIs          — role-scoped KPI data
 *   useProcurementTimeline    — PR→PO cycle times
 *   useSpendingAnalytics      — expenditure breakdown
 *   useSupplierPerformance    — supplier metrics
 *   useTenderStatistics       — tender outcome analytics
 *   useFinancialSummary       — invoiced/paid/outstanding
 *
 * Validates: Requirements 16.1, 16.2, 16.3, 22.1, 22.10
 */

import { useQuery } from '@tanstack/react-query';
import {
  getDashboardKPIs,
  getProcurementTimeline,
  getSpendingAnalytics,
  getSupplierPerformance,
  getTenderStatistics,
  getFinancialSummary,
} from '@/lib/api/reporting';
import type { ReportFilters } from '@/types/reporting';

// ─── Query keys ───────────────────────────────────────────────────────────────

export const reportingQueryKeys = {
  all: ['reports'] as const,
  dashboard: () => [...reportingQueryKeys.all, 'dashboard'] as const,
  timeline: (filters?: Partial<ReportFilters>) =>
    [...reportingQueryKeys.all, 'timeline', filters] as const,
  spending: (filters?: ReportFilters) =>
    [...reportingQueryKeys.all, 'spending', filters] as const,
  supplierPerf: (filters?: Pick<ReportFilters, 'date_from' | 'date_to' | 'supplier_id'>) =>
    [...reportingQueryKeys.all, 'supplier-performance', filters] as const,
  tenderStats: (filters?: Pick<ReportFilters, 'date_from' | 'date_to' | 'category' | 'status'>) =>
    [...reportingQueryKeys.all, 'tender-statistics', filters] as const,
  financial: (filters?: Pick<ReportFilters, 'date_from' | 'date_to' | 'department_id'>) =>
    [...reportingQueryKeys.all, 'financial-summary', filters] as const,
};

// ─── Dashboard KPIs ───────────────────────────────────────────────────────────

/**
 * Role-scoped KPI summary for the dashboard.
 * Data is cached for 5 minutes (matches backend Redis TTL).
 */
export function useDashboardKPIs() {
  return useQuery({
    queryKey: reportingQueryKeys.dashboard(),
    queryFn: getDashboardKPIs,
    staleTime: 5 * 60 * 1000, // 5 minutes
    refetchInterval: 5 * 60 * 1000,
  });
}

// ─── Procurement Timeline ─────────────────────────────────────────────────────

export function useProcurementTimeline(
  filters?: Pick<ReportFilters, 'date_from' | 'date_to' | 'department_id' | 'category'>,
) {
  return useQuery({
    queryKey: reportingQueryKeys.timeline(filters),
    queryFn: () => getProcurementTimeline(filters),
    staleTime: 5 * 60 * 1000,
  });
}

// ─── Spending Analytics ───────────────────────────────────────────────────────

export function useSpendingAnalytics(filters?: ReportFilters) {
  return useQuery({
    queryKey: reportingQueryKeys.spending(filters),
    queryFn: () => getSpendingAnalytics(filters),
    staleTime: 5 * 60 * 1000,
  });
}

// ─── Supplier Performance ─────────────────────────────────────────────────────

export function useSupplierPerformance(
  filters?: Pick<ReportFilters, 'date_from' | 'date_to' | 'supplier_id'>,
) {
  return useQuery({
    queryKey: reportingQueryKeys.supplierPerf(filters),
    queryFn: () => getSupplierPerformance(filters),
    staleTime: 5 * 60 * 1000,
  });
}

// ─── Tender Statistics ────────────────────────────────────────────────────────

export function useTenderStatistics(
  filters?: Pick<ReportFilters, 'date_from' | 'date_to' | 'category' | 'status'>,
) {
  return useQuery({
    queryKey: reportingQueryKeys.tenderStats(filters),
    queryFn: () => getTenderStatistics(filters),
    staleTime: 5 * 60 * 1000,
  });
}

// ─── Financial Summary ────────────────────────────────────────────────────────

export function useFinancialSummary(
  filters?: Pick<ReportFilters, 'date_from' | 'date_to' | 'department_id'>,
) {
  return useQuery({
    queryKey: reportingQueryKeys.financial(filters),
    queryFn: () => getFinancialSummary(filters),
    staleTime: 5 * 60 * 1000,
  });
}
