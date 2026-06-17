/**
 * API client functions for User Management.
 *
 * Covers: list users (paginated/searchable/filterable), create user,
 * update user, deactivate user, fetch single user, fetch available roles.
 *
 * All functions consume the standard ApiResponse<T> envelope.
 *
 * Validates: Requirements 4.1, 4.6, 22.6
 */

import { apiGet, apiPost, apiPatch, apiDelete } from '@/lib/api/client';
import type { ApiResponse, PaginatedResponse, ListQueryParams } from '@/types/api.types';
import type { User } from '@/types/models.types';

// ─── Query params specific to users ──────────────────────────────────────────

export interface UsersQueryParams extends ListQueryParams {
  role?: string;
  status?: 'active' | 'inactive' | 'locked';
  department_id?: string;
}

// ─── Request payloads ─────────────────────────────────────────────────────────

export interface CreateUserPayload {
  name: string;
  email: string;
  role: string;
  department_id?: string | null;
  phone?: string | null;
}

export interface UpdateUserPayload {
  name?: string;
  email?: string;
  role?: string;
  department_id?: string | null;
  phone?: string | null;
}

// ─── API functions ────────────────────────────────────────────────────────────

/**
 * Fetch a paginated, searchable list of users.
 */
export async function getUsers(params?: UsersQueryParams): Promise<PaginatedResponse<User>> {
  return apiGet<PaginatedResponse<User>>('/users', { params });
}

/**
 * Fetch a single user by ID.
 */
export async function getUser(id: string): Promise<ApiResponse<User>> {
  return apiGet<ApiResponse<User>>(`/users/${id}`);
}

/**
 * Create a new user within the active tenant.
 * The backend will send a welcome email with a 24-hour password-setup link.
 */
export async function createUser(payload: CreateUserPayload): Promise<ApiResponse<User>> {
  return apiPost<ApiResponse<User>>('/users', payload);
}

/**
 * Update an existing user.
 */
export async function updateUser(id: string, payload: UpdateUserPayload): Promise<ApiResponse<User>> {
  return apiPatch<ApiResponse<User>>(`/users/${id}`, payload);
}

/**
 * Deactivate (soft-disable) a user account.
 * The backend sets status to 'inactive'.
 * Returns HTTP 422 if the user has active PRs/POs.
 */
export async function deactivateUser(id: string): Promise<ApiResponse<User>> {
  return apiPatch<ApiResponse<User>>(`/users/${id}/deactivate`, {});
}

/**
 * Reactivate a previously deactivated user.
 */
export async function reactivateUser(id: string): Promise<ApiResponse<User>> {
  return apiPatch<ApiResponse<User>>(`/users/${id}/reactivate`, {});
}

/**
 * Hard-delete a user (only permitted when no active linked records exist).
 */
export async function deleteUser(id: string): Promise<ApiResponse<null>> {
  return apiDelete<ApiResponse<null>>(`/users/${id}`);
}
