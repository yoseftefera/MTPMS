/**
 * TanStack Query hooks for the Notification module.
 *
 * Hooks:
 *   useNotifications      — paginated + filterable notifications list
 *   useUnreadCount        — badge count, refetched every 60 s
 *   useMarkAsRead         — mutation: mark a single notification as read (optimistic)
 *   useMarkAllAsRead      — mutation: mark every notification as read (optimistic)
 *
 * Optimistic updates keep the UI snappy: the store state is updated immediately
 * and rolled back only if the server call fails.
 *
 * Validates: Requirements 15.6, 15.7, 22.5
 */

import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import {
  getNotifications,
  getUnreadCount,
  markAsRead,
  markAllAsRead,
} from '@/lib/api/notifications';
import { useNotificationStore } from '@/store/notificationStore';
import type { NotificationFilters } from '@/types/notification';

// ─── Query keys ───────────────────────────────────────────────────────────────

export const notificationQueryKeys = {
  all: ['notifications'] as const,
  lists: () => [...notificationQueryKeys.all, 'list'] as const,
  list: (filters?: NotificationFilters) =>
    [...notificationQueryKeys.lists(), filters] as const,
  unreadCount: () => [...notificationQueryKeys.all, 'unread-count'] as const,
};

// ─── Queries ──────────────────────────────────────────────────────────────────

/**
 * Paginated + filterable notifications list.
 */
export function useNotifications(filters?: NotificationFilters) {
  return useQuery({
    queryKey: notificationQueryKeys.list(filters),
    queryFn: () => getNotifications(filters),
  });
}

/**
 * Unread notification count for the bell badge.
 * Polls every 60 seconds so the count stays reasonably fresh even when
 * the Echo WebSocket is unavailable.
 */
export function useUnreadCount() {
  return useQuery({
    queryKey: notificationQueryKeys.unreadCount(),
    queryFn: () => getUnreadCount(),
    staleTime: 30_000,
    refetchInterval: 60_000,
    select: (res) => res.data?.unread_count ?? 0,
  });
}

// ─── Mutations ────────────────────────────────────────────────────────────────

/**
 * Marks a single notification as read.
 * Applies an optimistic update to the Zustand store immediately, then
 * invalidates TanStack Query caches on success (or rolls back on error).
 */
export function useMarkAsRead() {
  const queryClient = useQueryClient();
  const { markAsRead: markStoreAsRead } = useNotificationStore();

  return useMutation({
    mutationFn: (id: string) => markAsRead(id),

    // Optimistic: update Zustand store before the server responds
    onMutate: (id: string) => {
      markStoreAsRead(id);
      return { id };
    },

    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: notificationQueryKeys.lists() });
      queryClient.invalidateQueries({
        queryKey: notificationQueryKeys.unreadCount(),
      });
    },

    // On error, re-sync from the server by invalidating caches
    onError: () => {
      queryClient.invalidateQueries({ queryKey: notificationQueryKeys.all });
    },
  });
}

/**
 * Marks all notifications as read.
 * Applies an optimistic update to the Zustand store immediately, then
 * invalidates TanStack Query caches on success.
 */
export function useMarkAllAsRead() {
  const queryClient = useQueryClient();
  const { markAllAsRead: markStoreAllAsRead } = useNotificationStore();

  return useMutation({
    mutationFn: () => markAllAsRead(),

    // Optimistic: update Zustand store before the server responds
    onMutate: () => {
      markStoreAllAsRead();
    },

    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: notificationQueryKeys.lists() });
      queryClient.invalidateQueries({
        queryKey: notificationQueryKeys.unreadCount(),
      });
    },

    // On error, re-sync from the server by invalidating caches
    onError: () => {
      queryClient.invalidateQueries({ queryKey: notificationQueryKeys.all });
    },
  });
}
