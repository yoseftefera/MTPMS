/**
 * API client functions for Budget Management.
 *
 * Covers: list budgets, single budget, create, update, transfer, utilization report.
 *
 * Validates: Requirements 13.1, 13.10, 22.6
 */

import { apiGet, apiPost, apiPut } from '@/lib/api/client';
import type { ApiResponse, PaginatedResponse } from '@/types/api.types';
import type {
  Budget,
  BudgetFilters,
  CreateBudgetData,
  UpdateBudgetData,
  TransferBudgetData,
  UtilizationReport,
} from '@/types/budget';

// ─── Read ─────────────────────────────────────────────────────────────────────

/**
 * Fetch a paginated, filterable list of budgets.
 */
export async function getBudgets(filters?: BudgetFilters): Promise<PaginatedResponse<Budget>> {
  return apiGet<PaginatedResponse<Budget>>('/budgets', { params: filters });
}

/**
 * Fetch a single budget by ID.
 */
export async function getBudget(id: string): Promise<ApiResponse<Budget>> {
  return apiGet<ApiResponse<Budget>>(`/budgets/${id}`);
}

/**
 * Fetch the utilization report for a fiscal year.
 * If no year is provided the backend defaults to the current fiscal year.
 */
export async function getUtilizationReport(fiscalYear?: number): Promise<ApiResponse<UtilizationReport>> {
  return apiGet<ApiResponse<UtilizationReport>>('/budgets/utilization-report', {
    params: fiscalYear ? { fiscal_year: fiscalYear } : undefined,
  });
}

// ─── Mutations ────────────────────────────────────────────────────────────────

/**
 * Allocate a new annual budget for a department.
 */
export async function createBudget(payload: CreateBudgetData): Promise<ApiResponse<Budget>> {
  return apiPost<ApiResponse<Budget>>('/budgets', payload);
}

/**
 * Update an existing budget (e.g. adjust total_amount).
 */
export async function updateBudget(id: string, payload: UpdateBudgetData): Promise<ApiResponse<Budget>> {
  return apiPut<ApiResponse<Budget>>(`/budgets/${id}`, payload);
}

/**
 * Transfer a portion of budget from one department/budget to another.
 */
export async function transferBudget(payload: TransferBudgetData): Promise<ApiResponse<null>> {
  return apiPost<ApiResponse<null>>('/budgets/transfer', payload);
}
