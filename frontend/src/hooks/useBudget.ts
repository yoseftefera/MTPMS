/**
 * TanStack Query hooks for Budget Management.
 *
 * Hooks:
 *   useBudgets            — paginated + filterable budget list
 *   useBudget             — single budget by ID
 *   useUtilizationReport  — per-department utilization report
 *   useCreateBudget       — mutation: allocate new budget
 *   useUpdateBudget       — mutation: update existing budget
 *   useTransferBudget     — mutation: transfer between budgets
 *
 * Validates: Requirements 13.1, 13.10, 22.5
 */

import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import {
  getBudgets,
  getBudget,
  getUtilizationReport,
  createBudget,
  updateBudget,
  transferBudget,
} from '@/lib/api/budgets';
import type { BudgetFilters, CreateBudgetData, UpdateBudgetData, TransferBudgetData } from '@/types/budget';

// ─── Query keys ───────────────────────────────────────────────────────────────

export const budgetQueryKeys = {
  all: ['budgets'] as const,
  lists: () => [...budgetQueryKeys.all, 'list'] as const,
  list: (filters?: BudgetFilters) => [...budgetQueryKeys.lists(), filters] as const,
  details: () => [...budgetQueryKeys.all, 'detail'] as const,
  detail: (id: string) => [...budgetQueryKeys.details(), id] as const,
  utilization: (fiscalYear?: number) => [...budgetQueryKeys.all, 'utilization', fiscalYear] as const,
};

// ─── Queries ──────────────────────────────────────────────────────────────────

/**
 * Paginated + filterable budget list.
 */
export function useBudgets(filters?: BudgetFilters) {
  return useQuery({
    queryKey: budgetQueryKeys.list(filters),
    queryFn: () => getBudgets(filters),
  });
}

/**
 * Single budget by ID.
 */
export function useBudget(id: string) {
  return useQuery({
    queryKey: budgetQueryKeys.detail(id),
    queryFn: () => getBudget(id),
    enabled: Boolean(id),
  });
}

/**
 * Per-department utilization report for a fiscal year.
 */
export function useUtilizationReport(fiscalYear?: number) {
  return useQuery({
    queryKey: budgetQueryKeys.utilization(fiscalYear),
    queryFn: () => getUtilizationReport(fiscalYear),
  });
}

// ─── Mutations ────────────────────────────────────────────────────────────────

/**
 * Allocate a new annual budget.
 * Invalidates the budgets list and utilization report on success.
 */
export function useCreateBudget() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (payload: CreateBudgetData) => createBudget(payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: budgetQueryKeys.lists() });
      queryClient.invalidateQueries({ queryKey: ['budgets', 'utilization'] });
    },
  });
}

/**
 * Update an existing budget.
 */
export function useUpdateBudget() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: UpdateBudgetData }) =>
      updateBudget(id, payload),
    onSuccess: (_data, { id }) => {
      queryClient.invalidateQueries({ queryKey: budgetQueryKeys.lists() });
      queryClient.invalidateQueries({ queryKey: budgetQueryKeys.detail(id) });
      queryClient.invalidateQueries({ queryKey: ['budgets', 'utilization'] });
    },
  });
}

/**
 * Transfer budget between departments.
 * Invalidates all budget queries on success.
 */
export function useTransferBudget() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (payload: TransferBudgetData) => transferBudget(payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: budgetQueryKeys.all });
    },
  });
}
