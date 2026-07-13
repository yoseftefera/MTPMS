'use client';

/**
 * EchoProvider — Laravel Echo client for real-time WebSocket notifications.
 *
 * Sets up a single Echo instance backed by Pusher JS (Soketi-compatible).
 * On mount, subscribes to the user's private channel:
 *   `private-tenant.{tenantId}.user.{userId}`
 *
 * When a `NotificationCreated` event arrives on the channel, the new
 * notification is prepended to the notificationStore and the unread
 * count is incremented — no page refresh required.
 *
 * The Echo instance is torn down on unmount or when the user logs out.
 *
 * Validates: Requirements 15.6, 22.5
 */

import {
  createContext,
  useContext,
  useEffect,
  useRef,
  useCallback,
} from 'react';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { useAuthStore } from '@/store/authStore';
import { useNotificationStore } from '@/store/notificationStore';
import type { Notification } from '@/types/models.types';

// Make Pusher available globally (required by laravel-echo's Pusher connector)
if (typeof window !== 'undefined') {
  (window as unknown as Record<string, unknown>).Pusher = Pusher;
}

// ─── Context ──────────────────────────────────────────────────────────────────

interface EchoContextValue {
  echo: Echo<'pusher'> | null;
}

const EchoContext = createContext<EchoContextValue>({ echo: null });

export function useEcho() {
  return useContext(EchoContext);
}

// ─── Provider ─────────────────────────────────────────────────────────────────

interface EchoProviderProps {
  children: React.ReactNode;
}

export function EchoProvider({ children }: EchoProviderProps) {
  const echoRef = useRef<Echo<'pusher'> | null>(null);
  const channelRef = useRef<ReturnType<Echo<'pusher'>['private']> | null>(null);

  const { isAuthenticated, user, tenant, token } = useAuthStore((s) => ({
    isAuthenticated: s.isAuthenticated,
    user: s.user,
    tenant: s.tenant,
    token: s.token,
  }));

  const prependNotification = useNotificationStore(
    (s) => s.prependNotification,
  );

  // ── Tear down the current Echo instance ───────────────────────────────────

  const teardown = useCallback(() => {
    if (channelRef.current && echoRef.current) {
      try {
        echoRef.current.leave(channelRef.current.name as string);
      } catch {
        // ignore
      }
      channelRef.current = null;
    }
    if (echoRef.current) {
      try {
        echoRef.current.disconnect();
      } catch {
        // ignore
      }
      echoRef.current = null;
    }
  }, []);

  // ── Set up Echo + subscribe when the user is authenticated ────────────────

  useEffect(() => {
    if (!isAuthenticated || !user || !tenant || !token) {
      teardown();
      return;
    }

    // Avoid creating duplicate connections on hot-reload / StrictMode double-invocation
    if (echoRef.current) {
      teardown();
    }

    const echo = new Echo<'pusher'>({
      broadcaster: 'pusher',
      key: process.env.NEXT_PUBLIC_PUSHER_APP_KEY ?? '',
      wsHost: process.env.NEXT_PUBLIC_PUSHER_HOST ?? 'localhost',
      wsPort: Number(process.env.NEXT_PUBLIC_PUSHER_PORT ?? 6001),
      wssPort: Number(process.env.NEXT_PUBLIC_PUSHER_PORT ?? 6001),
      forceTLS: (process.env.NEXT_PUBLIC_PUSHER_SCHEME ?? 'http') === 'https',
      disableStats: true,
      enabledTransports: ['ws', 'wss'],
      cluster: process.env.NEXT_PUBLIC_PUSHER_APP_CLUSTER ?? 'mt1',
      // Soketi auth endpoint — same base URL as the API
      authEndpoint: `${process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000/api/v1'}/broadcasting/auth`,
      auth: {
        headers: {
          Authorization: `Bearer ${token}`,
          'X-Tenant-ID': tenant.id,
          Accept: 'application/json',
        },
      },
    });

    echoRef.current = echo;

    // Subscribe to the user's private channel
    const channelName = `tenant.${tenant.id}.user.${user.id}`;
    const channel = echo.private(channelName);
    channelRef.current = channel;

    // Listen for new notifications broadcast by the backend
    channel.listen('.NotificationCreated', (event: { notification: Notification }) => {
      if (event?.notification) {
        prependNotification(event.notification);
      }
    });

    return () => {
      teardown();
    };
    // Re-run when the authenticated user/tenant changes
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isAuthenticated, user?.id, tenant?.id, token]);

  return (
    <EchoContext.Provider value={{ echo: echoRef.current }}>
      {children}
    </EchoContext.Provider>
  );
}
