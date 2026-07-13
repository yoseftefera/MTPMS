/**
 * Notification domain types for the Procurement Management Platform.
 * Mirrors the backend Notification model and API resource shapes.
 *
 * Validates: Requirements 15.6, 15.7, 22.5
 */

import type { Notification } from "@/types/models.types"

// ─── Re-exports ───────────────────────────────────────────────────────────────

export type { Notification }

// ─── Filters ──────────────────────────────────────────────────────────────────

export type NotificationReadFilter = "" | "read" | "unread"

export interface NotificationFilters {
  page?: number
  per_page?: number
  event_type?: string
  is_read?: NotificationReadFilter
  date_from?: string
  date_to?: string
}

// ─── Unread count ─────────────────────────────────────────────────────────────

export interface UnreadCountPayload {
  unread_count: number
}
