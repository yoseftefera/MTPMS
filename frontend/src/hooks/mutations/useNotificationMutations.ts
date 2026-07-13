/**
 * Optimistic-update mutations for Notification actions.
 *
 * Hooks:
 *   useMarkNotificationRead    — mark a single notification as read;
 *                                immediately sets is_read: true in the Zustand
 *                                notificationStore and decrements the unread counter.
 *                                Rolls back (re-invalidates from server) on error.
 *   useMarkAllNotificationsRead — mark every notification as read;
 *                                 immediately zeros the unread counter in Zustand.
 *                                 Rolls back on error.
 *
 * Optimistic update strategy:
 *   - The Zustand notificationStore is the source of truth for the bell badge and
 *     the notification dropdown overlay (populated by Echo real-time events).
 *   - TanStack Query caches the paginated notification list shown on the full
 *     notifications history page.
 *   - On mutation, the Zustand store is updated synchronously (no await) before
 *     the API call resolves so the badge and dropdown reflect the change instantly.
 *   - On success the TanStack Query caches are invalidated to keep the history
 *     page in sync.
 *   - On error the caches are invalidated so the next render fetches the real
 *     state from the server, effectively rolling back the optimistic update.
 *
 * These are the canonical mutation hooks for notifications.
 * The implementations in `src/hooks/useNotifications.ts` are identical —
 * this file re-exports them from a single mutations barrel.
 *
 * Validates: Requirements 15.6, 15.7, 22.5, 22.7
 */

export {
  useMarkAsRead as useMarkNotificationRead,
  useMarkAllAsRead as useMarkAllNotificationsRead,
} from "@/hooks/useNotifications";
