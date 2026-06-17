'use client';

/**
 * TanStack Query provider.
 *
 * Wraps the application with a QueryClientProvider so all child components
 * can use useQuery / useMutation hooks.
 *
 * Configuration:
 * - staleTime: 30 s  — data is considered fresh for 30 seconds
 * - gcTime: 5 min    — inactive queries are garbage-collected after 5 minutes
 * - retry: 2         — failed requests are retried twice before surfacing an error
 * - refetchOnWindowFocus: false — avoids noisy refetches on tab switch
 *
 * Validates: Requirements 22.5, 22.7 (TanStack Query integration)
 */

import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { useState } from 'react';

interface QueryProviderProps {
  children: React.ReactNode;
}

export function QueryProvider({ children }: QueryProviderProps) {
  // Create the QueryClient inside useState so each test/render gets its own instance
  const [queryClient] = useState(
    () =>
      new QueryClient({
        defaultOptions: {
          queries: {
            staleTime: 30_000,        // 30 seconds
            gcTime: 5 * 60_000,       // 5 minutes
            retry: 2,
            refetchOnWindowFocus: false,
          },
        },
      }),
  );

  return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>;
}
