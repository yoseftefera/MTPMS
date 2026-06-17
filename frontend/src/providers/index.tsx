'use client';

/**
 * Root providers wrapper.
 *
 * Composes all application-level providers in the correct order:
 *   QueryProvider  →  ThemeProvider  →  AuthProvider  →  children
 *
 * Import this single component in the root layout to keep layout.tsx clean.
 */

import { QueryProvider } from './QueryProvider';
import { ThemeProvider } from './ThemeProvider';
import { AuthProvider } from './AuthProvider';

interface ProvidersProps {
  children: React.ReactNode;
}

export function Providers({ children }: ProvidersProps) {
  return (
    <QueryProvider>
      <ThemeProvider>
        <AuthProvider>{children}</AuthProvider>
      </ThemeProvider>
    </QueryProvider>
  );
}

export { QueryProvider } from './QueryProvider';
export { ThemeProvider } from './ThemeProvider';
export { AuthProvider, useAuth } from './AuthProvider';
