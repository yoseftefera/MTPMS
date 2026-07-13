/**
 * Barrel export for all optimistic-update mutation hooks.
 *
 * Import from here to get any action mutation with a single import:
 *
 *   import {
 *     useApproveDocument,
 *     useRejectDocument,
 *     useMarkNotificationRead,
 *   } from "@/hooks/mutations"
 *
 * ─── Approval mutations ───────────────────────────────────────────────────────
 *
 *   useApproveDocument({ approvalId, payload })
 *     Optimistically removes the record from the pending approvals list cache.
 *     Rolls back on server error.
 *
 *   useRejectDocument({ approvalId, payload })
 *     Optimistically removes the record from the pending approvals list cache.
 *     Rolls back on server error.
 *
 *   useReturnForRevision({ approvalId, payload })
 *     Optimistically removes the record from the pending approvals list cache.
 *     Rolls back on server error.
 *
 * ─── Notification mutations ───────────────────────────────────────────────────
 *
 *   useMarkNotificationRead(id: string)
 *     Optimistically sets is_read: true + decrements unread count in Zustand.
 *     Invalidates TanStack Query notification caches on settle.
 *     On error: re-invalidates from server (effective rollback).
 *
 *   useMarkAllNotificationsRead()
 *     Optimistically zeroes unread count in Zustand.
 *     Invalidates TanStack Query notification caches on settle.
 *     On error: re-invalidates from server (effective rollback).
 *
 * Validates: Requirements 6.3, 6.4, 6.5, 15.6, 15.7, 22.5, 22.7
 */

// Approval actions
export {
  useApproveDocument,
  useRejectDocument,
  useReturnForRevision,
} from "./useApprovalMutations";

// Notification actions
export {
  useMarkNotificationRead,
  useMarkAllNotificationsRead,
} from "./useNotificationMutations";
