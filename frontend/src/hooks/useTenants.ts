/**
 * TanStack Query hooks for Tenant Management (System_Admin).
 *
 * Hooks:
 *   useTenants           — paginated + filterable tenant list
 *   useTenant            — single tenant by ID
 *   useRegisterTenant    — mutation: register a new tenant
 *   useSuspendTenant     — mutation with optimistic update: suspend tenant
 *   useReactivateTenant  — mutation with optimistic update: reactivate tenant
 *   useDeactivateTenant  — mutation: deactivate tenant
 *   useTenantAnalytics   — cross-tenant aggregated analytics
 *
 * Validates: Requirements 1.6, 1.8
 */

import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import {
  getTenants,
  getTenant,
  registerTenant,
  suspendTenant,
  reactivateTenant,
  deactivateTenant,
  getTenantAnalytics,
  type TenantsQueryParams,
  type RegisterTenantPayload,
} from '@/lib/api/tenants';
import type { Tenant } from '@/types/models.types';
import type { PaginatedResponse } from '@/types/api.types';

// ─── Query keys ───────────────────────────────────────────────────────────────

export const tenantQueryKeys = {
  all: ['tenants'] as const,
  lists: () => [...tenantQueryKeys.all, 'list'] as const,
  list: (params?: TenantsQueryParams) => [...tenantQueryKeys.lists(), params] as const,
  details: () => [...tenantQueryKeys.all, 'detail'] as const,
  detail: (id: string) => [...tenantQueryKeys.details(), id] as const,
  analytics: () => [...tenantQueryKeys.all, 'analytics'] as const,
};

// ─── Queries ──────────────────────────────────────────────────────────────────

/**
 * Paginated + searchable tenant list.
 */
export function useTenants(params?: TenantsQueryParams) {
  return useQuery({
    queryKey: tenantQueryKeys.list(params),
    queryFn: () => getTenants(params),
  });
}

/**
 * Single tenant by ID.
 */
export function useTenant(id: string) {
  return useQuery({
    queryKey: tenantQueryKeys.detail(id),
    queryFn: () => getTenant(id),
    enabled: Boolean(id),
  });
}

/**
 * Cross-tenant aggregated analytics (System_Admin only).
 * Stale after 5 minutes to match backend Redis cache TTL.
 */
export function useTenantAnalytics() {
  return useQuery({
    queryKey: tenantQueryKeys.analytics(),
    queryFn: getTenantAnalytics,
    staleTime: 5 * 60 * 1000,
    refetchInterval: 5 * 60 * 1000,
  });
}

// ─── Mutations ────────────────────────────────────────────────────────────────

/**
 * Register a new tenant.
 * Invalidates the tenant list cache on success.
 */
export function useRegisterTenant() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (payload: RegisterTenantPayload) => registerTenant(payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: tenantQueryKeys.lists() });
      queryClient.invalidateQueries({ queryKey: tenantQueryKeys.analytics() });
    },
  });
}

/**
 * Suspend an active tenant.
 * Optimistically updates the tenant's status in the list and detail cache.
 * Rolls back on error.
 */
export function useSuspendTenant() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: string) => suspendTenant(id),

    onMutate: async (id: string) => {
      // Cancel any outgoing refetches that would overwrite our optimistic update
      await queryClient.cancelQueries({ queryKey: tenantQueryKeys.lists() });
      await queryClient.cancelQueries({ queryKey: tenantQueryKeys.detail(id) });

      // Snapshot all list query caches for rollback
      const previousLists = queryClient.getQueriesData<PaginatedResponse<Tenant>>({
        queryKey: tenantQueryKeys.lists(),
      });

      const previousDetail = queryClient.getQueryData(tenantQueryKeys.detail(id));

      // Optimistically update all list caches
      queryClient.setQueriesData<PaginatedResponse<Tenant>>(
        { queryKey: tenantQueryKeys.lists() },
        (old) => {
          if (!old) return old;
          return {
            ...old,
            data: old.data?.map((t) =>
              t.id === id ? { ...t, status: 'suspended' as const } : t,
            ) ?? old.data,
          };
        },
      );

      // Optimistically update detail cache
      queryClient.setQueryData(tenantQueryKeys.detail(id), (old: unknown) => {
        if (!old) return old;
        const response = old as { data?: Tenant };
        if (!response.data) return old;
        return { ...response, data: { ...response.data, status: 'suspended' } };
      });

      return { previousLists, previousDetail };
    },

    onError: (_err, id, context) => {
      // Roll back on error
      if (context?.previousLists) {
        for (const [queryKey, data] of context.previousLists) {
          queryClient.setQueryData(queryKey, data);
        }
      }
      if (context?.previousDetail) {
        queryClient.setQueryData(tenantQueryKeys.detail(id), context.previousDetail);
      }
    },

    onSettled: (_data, _err, id) => {
      queryClient.invalidateQueries({ queryKey: tenantQueryKeys.lists() });
      queryClient.invalidateQueries({ queryKey: tenantQueryKeys.detail(id) });
      queryClient.invalidateQueries({ queryKey: tenantQueryKeys.analytics() });
    },
  });
}

/**
 * Reactivate a suspended tenant.
 * Optimistically updates the tenant's status in the list and detail cache.
 * Rolls back on error.
 */
export function useReactivateTenant() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: string) => reactivateTenant(id),

    onMutate: async (id: string) => {
      await queryClient.cancelQueries({ queryKey: tenantQueryKeys.lists() });
      await queryClient.cancelQueries({ queryKey: tenantQueryKeys.detail(id) });

      const previousLists = queryClient.getQueriesData<PaginatedResponse<Tenant>>({
        queryKey: tenantQueryKeys.lists(),
      });

      const previousDetail = queryClient.getQueryData(tenantQueryKeys.detail(id));

      queryClient.setQueriesData<PaginatedResponse<Tenant>>(
        { queryKey: tenantQueryKeys.lists() },
        (old) => {
          if (!old) return old;
          return {
            ...old,
            data: old.data?.map((t) =>
              t.id === id ? { ...t, status: 'active' as const } : t,
            ) ?? old.data,
          };
        },
      );

      queryClient.setQueryData(tenantQueryKeys.detail(id), (old: unknown) => {
        if (!old) return old;
        const response = old as { data?: Tenant };
        if (!response.data) return old;
        return { ...response, data: { ...response.data, status: 'active' } };
      });

      return { previousLists, previousDetail };
    },

    onError: (_err, id, context) => {
      if (context?.previousLists) {
        for (const [queryKey, data] of context.previousLists) {
          queryClient.setQueryData(queryKey, data);
        }
      }
      if (context?.previousDetail) {
        queryClient.setQueryData(tenantQueryKeys.detail(id), context.previousDetail);
      }
    },

    onSettled: (_data, _err, id) => {
      queryClient.invalidateQueries({ queryKey: tenantQueryKeys.lists() });
      queryClient.invalidateQueries({ queryKey: tenantQueryKeys.detail(id) });
      queryClient.invalidateQueries({ queryKey: tenantQueryKeys.analytics() });
    },
  });
}

/**
 * Deactivate a tenant permanently.
 * Invalidates list and detail caches on success.
 */
export function useDeactivateTenant() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: string) => deactivateTenant(id),
    onSuccess: (_data, id) => {
      queryClient.invalidateQueries({ queryKey: tenantQueryKeys.lists() });
      queryClient.invalidateQueries({ queryKey: tenantQueryKeys.detail(id) });
      queryClient.invalidateQueries({ queryKey: tenantQueryKeys.analytics() });
    },
  });
}
