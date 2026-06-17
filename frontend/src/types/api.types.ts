import type { User, Tenant, Notification } from './models.types';

/**
 * Standard API response envelope used by all PMP API endpoints.
 * Validates: Requirements 18.2, 18.3
 */
export interface ApiResponse<T = unknown> {
  success: boolean;
  data: T | null;
  message: string;
  errors: Record<string, string[]> | null;
  meta: PaginationMeta | null;
}

export interface PaginationMeta {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
  from: number | null;
  to: number | null;
}

export interface PaginatedResponse<T> extends ApiResponse<T[]> {
  meta: PaginationMeta;
}

export interface ListQueryParams {
  page?: number;
  per_page?: number;
  sort_by?: string;
  sort_dir?: 'asc' | 'desc';
  search?: string;
  status?: string;
  department_id?: string;
  date_from?: string;
  date_to?: string;
  include?: string;
}

export interface LoginCredentials {
  email: string;
  password: string;
  tenant_id?: string;
}

export interface LoginResponse {
  token: string;
  token_type: string;
  expires_in: number;
  user: User;
  tenant: Tenant;
}

export interface RefreshTokenResponse {
  token: string;
  token_type: string;
  expires_in: number;
}

// Re-export model types for convenience
export type { User, Tenant, Notification };
