'use client';

/**
 * Auth provider.
 *
 * Responsibilities:
 * 1. Registers the authStore getter with the Axios client so the interceptor
 *    can inject the Bearer token and X-Tenant-ID header without importing
 *    React hooks (which would violate hook rules).
 * 2. On mount, if a token is present in the store, fetches the current user
 *    profile from GET /auth/me to validate the token and refresh user data.
 * 3. Exposes the auth context via the useAuth hook for convenience.
 *
 * Validates: Requirements 22.5, 22.6 (auth state management), 2.1 (JWT)
 */

import { createContext, useContext, useEffect, useCallback } from 'react';
import { useAuthStore, getAuthSnapshot } from '@/store/authStore';
import { registerAuthStateGetter } from '@/lib/api/client';
import { apiGet } from '@/lib/api/client';
import type { ApiResponse } from '@/types/api.types';
import type { User } from '@/types/models.types';

interface AuthContextValue {
  isAuthenticated: boolean;
  isLoading: boolean;
}

const AuthContext = createContext<AuthContextValue>({
  isAuthenticated: false,
  isLoading: false,
});

interface AuthProviderProps {
  children: React.ReactNode;
}

export function AuthProvider({ children }: AuthProviderProps) {
  const { isAuthenticated, token, setUser, logout } = useAuthStore((state) => ({
    isAuthenticated: state.isAuthenticated,
    token: state.token,
    setUser: state.setUser,
    logout: state.logout,
  }));

  // Register the auth state getter with the Axios client once on mount.
  // This avoids circular imports between the store and the client module.
  useEffect(() => {
    registerAuthStateGetter(getAuthSnapshot);
  }, []);

  // Validate the stored token by fetching the current user profile.
  const validateToken = useCallback(async () => {
    if (!token) return;

    try {
      const response = await apiGet<ApiResponse<User>>('/auth/me');
      if (response.success && response.data) {
        setUser(response.data);
      } else {
        logout();
      }
    } catch {
      // 401 is handled by the Axios interceptor which calls logout() and redirects.
      // Any other error (network, 5xx) — keep the stored state to avoid logging
      // the user out on a transient server error.
    }
  }, [token, setUser, logout]);

  useEffect(() => {
    validateToken();
    // Only run on mount — token changes are handled by setAuth/logout actions
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  return (
    <AuthContext.Provider value={{ isAuthenticated, isLoading: false }}>
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth() {
  return useContext(AuthContext);
}
