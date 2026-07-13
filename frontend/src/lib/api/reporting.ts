/**
 * API client functions for Reporting & Analytics.
 *
 * All endpoints hit GET /api/v1/reports/* (backed by ReportingService).
 * Export endpoints hit /api/v1/reports/*/export with format=pdf|excel.
 *
 * Validates: Requirements 16.1, 16.2, 16.3, 16.7, 16.8
 */

import { apiGet } from '@/lib/api/client';
import apiClient from '@/lib/api/client';
import type { ApiResponse } from '@/types/api.types';
import type {
  DashboardKPIs,
  ProcurementTimelineData,
  SpendingAnalyticsData,
  SupplierPerformanceData,
  TenderStatisticsData,
  FinancialSummaryData,
  ReportFilters,
} from '@/types/reporting';

// ─── Dashboard ────────────────────────────────────────────────────────────────

/**
 * Fetch role-scoped dashboard KPIs.
 * Backend caches this for 5 minutes per tenant/role.
 */
export async function getDashboardKPIs(): Promise<ApiResponse<DashboardKPIs>> {
  return apiGet<ApiResponse<DashboardKPIs>>('/reports/dashboard');
}

// ─── Procurement Timeline ─────────────────────────────────────────────────────

export async function getProcurementTimeline(
  filters?: Pick<ReportFilters, 'date_from' | 'date_to' | 'department_id' | 'category'>,
): Promise<ApiResponse<ProcurementTimelineData>> {
  return apiGet<ApiResponse<ProcurementTimelineData>>('/reports/procurement-timeline', {
    params: filters,
  });
}

// ─── Spending Analytics ───────────────────────────────────────────────────────

export async function getSpendingAnalytics(
  filters?: ReportFilters,
): Promise<ApiResponse<SpendingAnalyticsData>> {
  return apiGet<ApiResponse<SpendingAnalyticsData>>('/reports/spending-analytics', {
    params: filters,
  });
}

// ─── Supplier Performance ─────────────────────────────────────────────────────

export async function getSupplierPerformance(
  filters?: Pick<ReportFilters, 'date_from' | 'date_to' | 'supplier_id'>,
): Promise<ApiResponse<SupplierPerformanceData>> {
  return apiGet<ApiResponse<SupplierPerformanceData>>('/reports/supplier-performance', {
    params: filters,
  });
}

// ─── Tender Statistics ────────────────────────────────────────────────────────

export async function getTenderStatistics(
  filters?: Pick<ReportFilters, 'date_from' | 'date_to' | 'category' | 'status'>,
): Promise<ApiResponse<TenderStatisticsData>> {
  return apiGet<ApiResponse<TenderStatisticsData>>('/reports/tender-statistics', {
    params: filters,
  });
}

// ─── Financial Summary ────────────────────────────────────────────────────────

export async function getFinancialSummary(
  filters?: Pick<ReportFilters, 'date_from' | 'date_to' | 'department_id'>,
): Promise<ApiResponse<FinancialSummaryData>> {
  return apiGet<ApiResponse<FinancialSummaryData>>('/reports/financial-summary', {
    params: filters,
  });
}

// ─── Exports ──────────────────────────────────────────────────────────────────

type ReportType =
  | 'spending-analytics'
  | 'supplier-performance'
  | 'tender-statistics'
  | 'financial-summary'
  | 'procurement-timeline';

/**
 * Trigger a synchronous Excel export.
 * Returns a Blob that the caller should initiate as a file download.
 * For datasets > 10,000 rows, the backend will return 202 Accepted
 * and send a notification when the async export is ready.
 */
export async function exportReportExcel(
  reportType: ReportType,
  filters?: ReportFilters,
): Promise<Blob | null> {
  const response = await apiClient.get(`/reports/${reportType}/export`, {
    params: { ...filters, format: 'excel' },
    responseType: 'blob',
  });

  // 202 means async — caller should handle the notification flow
  if (response.status === 202) return null;

  return response.data as Blob;
}

/**
 * Trigger a synchronous PDF export.
 * Returns a Blob for inline open/download or null for async (202).
 */
export async function exportReportPDF(
  reportType: ReportType,
  filters?: ReportFilters,
): Promise<Blob | null> {
  const response = await apiClient.get(`/reports/${reportType}/export`, {
    params: { ...filters, format: 'pdf' },
    responseType: 'blob',
  });

  if (response.status === 202) return null;

  return response.data as Blob;
}
