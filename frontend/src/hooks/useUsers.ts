/**
 * TanStack Query hooks for User Management.
 *
 * Hooks:
 *   useUsers         — paginated + filterable user list
 *   useUser          — single user by ID
 *   useCreateUser    — mutation: create new user
 *   useUpdateUser    — mutation: update user fields
 *   useDeactivateUser — mutation: deactivate user with cache invalidation
 *   useReactivateUser — mutation: reactivate user
 *   useDeleteUser    — mutation: hard-delete user
 *
 * Validates: Requirements 4.1, 4.6, 22.5, 22.7
 */

import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import {
  getUsers,
  getUser,
  createUser,
  updateUser,
  deactivateUser,
  reactivateUser,
  deleteUser,
  type UsersQueryParams,
  type CreateUserPayload,
  type UpdateUserPayload,
} from '@/lib/api/users';

// ─── Query keys ───────────────────────────────────────────────────────────────

export const userQueryKeys = {
  all: ['users'] as const,
  lists: () => [...userQueryKeys.all, 'list'] as const,
  list: (params?: UsersQueryParams) => [...userQueryKeys.lists(), params] as const,
  details: () => [...userQueryKeys.all, 'detail'] as const,
  detail: (id: string) => [...userQueryKeys.details(), id] as const,
};

// ─── Queries ──────────────────────────────────────────────────────────────────

/**
 * Paginated + searchable user list.
 */
export function useUsers(params?: UsersQueryParams) {
  return useQuery({
    queryKey: userQueryKeys.list(params),
    queryFn: () => getUsers(params),
  });
}

/**
 * Single user by ID.
 */
export function useUser(id: string) {
  return useQuery({
    queryKey: userQueryKeys.detail(id),
    queryFn: () => getUser(id),
    enabled: Boolean(id),
  });
}

// ─── Mutations ────────────────────────────────────────────────────────────────

/**
 * Create a new user.
 * Invalidates the users list cache on success.
 */
export function useCreateUser() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (payload: CreateUserPayload) => createUser(payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: userQueryKeys.lists() });
    },
  });
}

/**
 * Update user fields (name, email, role, department).
 * Invalidates both the list and the specific user detail cache.
 */
export function useUpdateUser(id: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (payload: UpdateUserPayload) => updateUser(id, payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: userQueryKeys.lists() });
      queryClient.invalidateQueries({ queryKey: userQueryKeys.detail(id) });
    },
  });
}

/**
 * Deactivate a user account.
 * Optimistically updates the user's status in the cache.
 */
export function useDeactivateUser() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: string) => deactivateUser(id),
    onSuccess: (_data, id) => {
      queryClient.invalidateQueries({ queryKey: userQueryKeys.lists() });
      queryClient.invalidateQueries({ queryKey: userQueryKeys.detail(id) });
    },
  });
}

/**
 * Reactivate a user account.
 */
export function useReactivateUser() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: string) => reactivateUser(id),
    onSuccess: (_data, id) => {
      queryClient.invalidateQueries({ queryKey: userQueryKeys.lists() });
      queryClient.invalidateQueries({ queryKey: userQueryKeys.detail(id) });
    },
  });
}

/**
 * Hard-delete a user (admin only, only when no active linked records).
 */
export function useDeleteUser() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: string) => deleteUser(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: userQueryKeys.lists() });
    },
  });
}
