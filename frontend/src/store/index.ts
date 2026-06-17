/**
 * Store barrel export.
 * Import stores from '@/store' for convenience.
 */

export { useAuthStore, getAuthSnapshot, selectUser, selectToken, selectTenant, selectRole, selectIsAuthenticated } from './authStore';
export { useNotificationStore, selectUnreadCount, selectNotifications } from './notificationStore';
export { useUIStore, selectTheme, selectSidebarOpen, selectSidebarCollapsed } from './uiStore';
export type { Theme } from './uiStore';
