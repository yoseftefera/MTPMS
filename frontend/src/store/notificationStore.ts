/**
 * Zustand notification store.
 *
 * Tracks the unread notification count and the in-memory notification list.
 * The list is populated by the NotificationProvider via Laravel Echo events
 * and by the initial fetch on mount.
 *
 * Validates: Requirements 15.6, 15.7 (real-time notifications), 22.5
 */

import { create } from 'zustand';
import type { Notification } from '@/types/models.types';

interface NotificationState {
  unreadCount: number;
  notifications: Notification[];

  // Actions
  setUnreadCount: (count: number) => void;
  setNotifications: (notifications: Notification[]) => void;
  prependNotification: (notification: Notification) => void;
  markAsRead: (id: string) => void;
  markAllAsRead: () => void;
  incrementUnread: () => void;
}

export const useNotificationStore = create<NotificationState>()((set) => ({
  unreadCount: 0,
  notifications: [],

  setUnreadCount: (count) => set({ unreadCount: count }),

  setNotifications: (notifications) => {
    const unreadCount = notifications.filter((n) => !n.is_read).length;
    set({ notifications, unreadCount });
  },

  prependNotification: (notification) =>
    set((state) => ({
      notifications: [notification, ...state.notifications],
      unreadCount: notification.is_read ? state.unreadCount : state.unreadCount + 1,
    })),

  markAsRead: (id) =>
    set((state) => {
      const notifications = state.notifications.map((n) =>
        n.id === id ? { ...n, is_read: true, read_at: new Date().toISOString() } : n,
      );
      const unreadCount = notifications.filter((n) => !n.is_read).length;
      return { notifications, unreadCount };
    }),

  markAllAsRead: () =>
    set((state) => ({
      notifications: state.notifications.map((n) => ({
        ...n,
        is_read: true,
        read_at: n.read_at ?? new Date().toISOString(),
      })),
      unreadCount: 0,
    })),

  incrementUnread: () => set((state) => ({ unreadCount: state.unreadCount + 1 })),
}));

// Selector helpers
export const selectUnreadCount = (state: NotificationState) => state.unreadCount;
export const selectNotifications = (state: NotificationState) => state.notifications;
