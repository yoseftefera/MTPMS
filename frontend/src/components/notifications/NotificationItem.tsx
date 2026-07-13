'use client';

/**
 * NotificationItem — renders a single notification row.
 *
 * Shows the notification title, truncated message, relative time,
 * and an unread indicator dot. Clicking an unread notification marks
 * it as read (optimistic update).
 *
 * Validates: Requirements 15.6, 15.7, 22.5
 */

import { cn } from '@/lib/utils';
import { useMarkAsRead } from '@/hooks/useNotifications';
import type { Notification } from '@/types/models.types';

// ─── Relative time helper ─────────────────────────────────────────────────────

function relativeTime(isoString: string): string {
  const diff = Date.now() - new Date(isoString).getTime();
  const seconds = Math.floor(diff / 1000);
  if (seconds < 60) return 'just now';
  const minutes = Math.floor(seconds / 60);
  if (minutes < 60) return `${minutes}m ago`;
  const hours = Math.floor(minutes / 60);
  if (hours < 24) return `${hours}h ago`;
  const days = Math.floor(hours / 24);
  if (days < 7) return `${days}d ago`;
  return new Date(isoString).toLocaleDateString();
}

// ─── Event type label map ─────────────────────────────────────────────────────

const EVENT_TYPE_LABELS: Record<string, string> = {
  purchase_request_submitted: 'Purchase Request',
  purchase_request_status_changed: 'PR Status',
  tender_published: 'Tender',
  bid_deadline_approaching: 'Bid Deadline',
  bid_evaluation_completed: 'Bid Evaluation',
  purchase_order_issued: 'Purchase Order',
  purchase_order_status_changed: 'PO Status',
  goods_receipt_created: 'Goods Receipt',
  invoice_submitted: 'Invoice',
  invoice_status_changed: 'Invoice Status',
  payment_processed: 'Payment',
  budget_threshold_reached: 'Budget Alert',
  contract_renewal_alert: 'Contract Renewal',
  account_locked: 'Account',
  low_stock_alert: 'Inventory',
};

// ─── Component ────────────────────────────────────────────────────────────────

interface NotificationItemProps {
  notification: Notification;
  /** When true, renders in compact mode (used in the bell dropdown) */
  compact?: boolean;
}

export function NotificationItem({ notification, compact = false }: NotificationItemProps) {
  const { mutate: markAsRead, isPending } = useMarkAsRead();

  const handleClick = () => {
    if (!notification.is_read && !isPending) {
      markAsRead(notification.id);
    }
  };

  const typeLabel = EVENT_TYPE_LABELS[notification.event_type] ?? notification.event_type;

  return (
    <button
      type="button"
      onClick={handleClick}
      disabled={notification.is_read || isPending}
      className={cn(
        'w-full text-left transition-colors',
        compact ? 'px-4 py-3' : 'px-4 py-4 border-b border-border last:border-b-0',
        !notification.is_read
          ? 'bg-primary/5 hover:bg-primary/10 cursor-pointer'
          : 'bg-transparent hover:bg-muted/40',
        isPending && 'opacity-60 pointer-events-none',
      )}
      aria-label={notification.is_read ? notification.title : `Mark as read: ${notification.title}`}
    >
      <div className="flex items-start gap-3">
        {/* Unread indicator */}
        <span
          className={cn(
            'mt-1.5 h-2 w-2 shrink-0 rounded-full',
            notification.is_read ? 'bg-transparent' : 'bg-primary',
          )}
          aria-hidden="true"
        />

        <div className="flex-1 min-w-0">
          {/* Event type tag + time */}
          <div className="flex items-center justify-between gap-2 mb-0.5">
            <span className="text-xs font-medium text-muted-foreground uppercase tracking-wide">
              {typeLabel}
            </span>
            <span className="text-xs text-muted-foreground shrink-0">
              {relativeTime(notification.created_at)}
            </span>
          </div>

          {/* Title */}
          <p
            className={cn(
              'text-sm leading-snug',
              notification.is_read ? 'text-muted-foreground' : 'text-foreground font-medium',
            )}
          >
            {notification.title}
          </p>

          {/* Message (truncated in compact mode) */}
          <p
            className={cn(
              'text-xs text-muted-foreground mt-0.5',
              compact ? 'line-clamp-1' : 'line-clamp-2',
            )}
          >
            {notification.message}
          </p>
        </div>
      </div>
    </button>
  );
}
