/**
 * API client functions for the Notification module.
 *
 * Covers:
 *   - GET  /notifications            — paginated + filterable list
 *   - GET  /notifications/unread-count — integer badge count
 *   - PATCH /notifications/{id}/read  — mark one notification as read
 *   - PATCH /notifications/read-all   — mark every notification as read
 *
 * Validates: Requirements 15.6, 15.7, 22.5
 */

import { apiGet, apiPatch } from "@/lib/api/client"
import type { ApiResponse, PaginatedResponse } from "@/types/api.types"
import type {
  Notification,
  NotificationFilters,
  UnreadCountPayload,
} from "@/types/notification"

// ─── List ─────────────────────────────────────────────────────────────────────

/**
 * Paginated + filterable list of notifications for the authenticated user.
 */
export async function getNotifications(
  params?: NotificationFilters,
): Promise<PaginatedResponse<Notification>> {
  return apiGet<PaginatedResponse<Notification>>("/notifications", { params })
}

// ─── Unread count ─────────────────────────────────────────────────────────────

/**
 * Returns the count of unread notifications for the authenticated user.
 */
export async function getUnreadCount(): Promise<ApiResponse<UnreadCountPayload>> {
  return apiGet<ApiResponse<UnreadCountPayload>>("/notifications/unread-count")
}

// ─── Mark one as read ─────────────────────────────────────────────────────────

/**
 * Marks a single notification as read.
 */
export async function markAsRead(id: string): Promise<ApiResponse<Notification>> {
  return apiPatch<ApiResponse<Notification>>(`/notifications/${id}/read`)
}

// ─── Mark all as read ─────────────────────────────────────────────────────────

/**
 * Marks all of the authenticated user's notifications as read.
 */
export async function markAllAsRead(): Promise<ApiResponse<null>> {
  return apiPatch<ApiResponse<null>>("/notifications/read-all")
}
