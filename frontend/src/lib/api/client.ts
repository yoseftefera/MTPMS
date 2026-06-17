/**
 * Axios HTTP client for the PMP API.
 *
 * Features:
 * - Base URL from NEXT_PUBLIC_API_URL environment variable
 * - Automatic Bearer token injection from authStore
 * - X-Request-ID header on every request (UUID v4)
 * - X-Tenant-ID header injection from authStore
 * - 401 response handling: clears auth state and redirects to /login
 * - Response unwrapping: returns the ApiResponse envelope as-is
 *
 * Validates: Requirements 18.10 (X-Request-ID), 22.6 (API integration)
 */

import axios, {
  type AxiosInstance,
  type AxiosRequestConfig,
  type AxiosResponse,
  type InternalAxiosRequestConfig,
} from 'axios';
import { v4 as uuidv4 } from 'uuid';

// Lazy import to avoid circular dependency with the store
let getAuthState: (() => { token: string | null; tenant: { id: string } | null; logout: () => void }) | null = null;

export function registerAuthStateGetter(
  getter: () => { token: string | null; tenant: { id: string } | null; logout: () => void },
) {
  getAuthState = getter;
}

const apiClient: AxiosInstance = axios.create({
  baseURL: process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000/api/v1',
  timeout: 30_000,
  headers: {
    'Content-Type': 'application/json',
    Accept: 'application/json',
  },
});

// ─── Request interceptor ──────────────────────────────────────────────────────

apiClient.interceptors.request.use(
  (config: InternalAxiosRequestConfig) => {
    // Inject a unique request ID for traceability (Requirement 18.10)
    config.headers['X-Request-ID'] = uuidv4();

    if (getAuthState) {
      const { token, tenant } = getAuthState();

      if (token) {
        config.headers['Authorization'] = `Bearer ${token}`;
      }

      if (tenant?.id) {
        config.headers['X-Tenant-ID'] = tenant.id;
      }
    }

    return config;
  },
  (error) => Promise.reject(error),
);

// ─── Response interceptor ─────────────────────────────────────────────────────

apiClient.interceptors.response.use(
  (response: AxiosResponse) => response,
  (error) => {
    if (error.response?.status === 401 && getAuthState) {
      // Token expired or invalid — clear auth state and redirect to login
      const { logout } = getAuthState();
      logout();

      if (typeof window !== 'undefined') {
        window.location.href = '/login';
      }
    }

    return Promise.reject(error);
  },
);

export default apiClient;

// ─── Typed helper wrappers ────────────────────────────────────────────────────

export async function apiGet<T>(url: string, config?: AxiosRequestConfig): Promise<T> {
  const response = await apiClient.get<T>(url, config);
  return response.data;
}

export async function apiPost<T>(url: string, data?: unknown, config?: AxiosRequestConfig): Promise<T> {
  const response = await apiClient.post<T>(url, data, config);
  return response.data;
}

export async function apiPatch<T>(url: string, data?: unknown, config?: AxiosRequestConfig): Promise<T> {
  const response = await apiClient.patch<T>(url, data, config);
  return response.data;
}

export async function apiPut<T>(url: string, data?: unknown, config?: AxiosRequestConfig): Promise<T> {
  const response = await apiClient.put<T>(url, data, config);
  return response.data;
}

export async function apiDelete<T>(url: string, config?: AxiosRequestConfig): Promise<T> {
  const response = await apiClient.delete<T>(url, config);
  return response.data;
}
