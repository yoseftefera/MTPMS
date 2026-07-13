'use client';

/**
 * Root providers wrapper.
 *
 * Composes all application-level providers in the correct order:
 *   QueryProvider → ThemeProvider → AuthProvider → EchoProvider → children
 *
 * EchoProvider is placed inside AuthProvider so it can read the authenticated
 * user/tenant/token from the auth store and set up the private WebSocket channel.
 */

import { QueryProvider } from './QueryProvider';
import { ThemeProvider } from './ThemeProvider';
import { AuthProvider } from './AuthProvider';
import { EchoProvider } from './EchoProvider';

interface ProvidersProps {
  children: React.ReactNode;
}

export function Providers({ children }: ProvidersProps) {
  return (
    <QueryProvider>
      <ThemeProvider>
        <AuthProvider>
          <EchoProvider>{children}</EchoProvider>
        </AuthProvider>
      </ThemeProvider>
    </QueryProvider>
  );
}

export { QueryProvider } from './QueryProvider';
export { ThemeProvider } from './ThemeProvider';
export { AuthProvider, useAuth } from './AuthProvider';
export { EchoProvider, useEcho } from './EchoProvider';
