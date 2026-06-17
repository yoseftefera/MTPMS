/**
 * Zustand auth store.
 *
 * Holds the authenticated user, JWT token, active tenant, and role.
 * Persists token to localStorage so sessions survive page refreshes.
 *
 * Validates: Requirements 22.5, 22.6 (state management), 2.1 (JWT handling)
 */

import { create } from 'zustand';
import { persist, createJSONStorage } from 'zustand/middleware';
import type { User, Tenant } from '@/types/models.types';
import type { LoginCredentials, LoginResponse } from '@/types/api.types';

interface AuthState {
  user: User | null;
  token: string | null;
  tenant: Tenant | null;
  role: string | null;
  isAuthenticated: boolean;

  // Actions
  setAuth: (payload: LoginResponse) => void;
  setUser: (user: User) => void;
  logout: () => void;
}

export const useAuthStore = create<AuthState>()(
  persist(
    (set) => ({
      user: null,
      token: null,
      tenant: null,
      role: null,
      isAuthenticated: false,

      setAuth: (payload: LoginResponse) => {
        const role = payload.user.roles?.[0] ?? null;
        set({
          user: payload.user,
          token: payload.token,
          tenant: payload.tenant,
          role,
          isAuthenticated: true,
        });
      },

      setUser: (user: User) => {
        const role = user.roles?.[0] ?? null;
        set({ user, role });
      },

      logout: () => {
        set({
          user: null,
          token: null,
          tenant: null,
          role: null,
          isAuthenticated: false,
        });
      },
    }),
    {
      name: 'pmp-auth',
      storage: createJSONStorage(() => localStorage),
      // Only persist the token and tenant — user/role are re-hydrated from the token
      partialize: (state) => ({
        token: state.token,
        tenant: state.tenant,
        user: state.user,
        role: state.role,
        isAuthenticated: state.isAuthenticated,
      }),
    },
  ),
);

// Selector helpers
export const selectUser = (state: AuthState) => state.user;
export const selectToken = (state: AuthState) => state.token;
export const selectTenant = (state: AuthState) => state.tenant;
export const selectRole = (state: AuthState) => state.role;
export const selectIsAuthenticated = (state: AuthState) => state.isAuthenticated;

// Expose a plain getter for the Axios client (avoids React hook rules)
export function getAuthSnapshot() {
  const state = useAuthStore.getState();
  return {
    token: state.token,
    tenant: state.tenant,
    logout: state.logout,
  };
}

// Unused import kept for type-checking the LoginCredentials shape
export type { LoginCredentials };
