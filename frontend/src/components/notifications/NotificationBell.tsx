'use client';

/**
 * NotificationBell — top-nav notification bell with unread count badge
 * and a Popover dropdown showing the 10 most recent notifications.
 *
 * Features:
 * - Red badge with unread count (hidden when 0)
 * - Badge shows "99+" when unread count exceeds 99
 * - Recent notifications list (max 10) inside a Popover
 * - "Mark all as read" button in the dropdown header
 * - "View all" link navigating to /notifications
 * - Optimistic mark-as-read on individual items
 * - Dismisses the popover after "Mark all as read"
 * - Empty state when there are no notifications
 * - Loading skeleton while the initial fetch is in-flight
 *
 * The unread count is kept in sync by:
 *  1. TanStack Query polling every 60 s
 *  2. Laravel Echo real-time events via EchoProvider (prependNotification)
 *
 * Validates: Requirements 15.6, 15.7, 22.5
 */

import { useState, useEffect } from 'react';
import { Bell } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover';
import { ScrollArea } from '@/components/ui/scroll-area';
import { Skeleton } from '@/components/ui/skeleton';
import { NotificationItem } from './NotificationItem';
import {
  useNotifications,
  useUnreadCount,
  useMarkAllAsRead,
} from '@/hooks/useNotifications';
import { useNotificationStore } from '@/store/notificationStore';
import { cn } from '@/lib/utils';

// ─── Badge ────────────────────────────────────────────────────────────────────

function UnreadBadge({ count }: { count: number }) {
  if (count === 0) return null;
  return (
    <span
      className="absolute -top-1 -right-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-destructive px-1 text-[10px] font-bold text-destructive-foreground leading-none"
      aria-label={`${count} unread notifications`}
    >
      {count > 99 ? '99+' : count}
    </span>
  );
}

// ─── Dropdown skeleton ────────────────────────────────────────────────────────

function NotificationSkeleton() {
  return (
    <div className="space-y-0">
      {Array.from({ length: 4 }).map((_, i) => (
        <div key={i} className="flex items-start gap-3 px-4 py-3 border-b border-border last:border-b-0">
          <Skeleton className="h-2 w-2 rounded-full mt-1.5 shrink-0" />
          <div className="flex-1 space-y-1.5">
            <Skeleton className="h-3 w-24" />
            <Skeleton className="h-3.5 w-48" />
            <Skeleton className="h-3 w-36" />
          </div>
        </div>
      ))}
    </div>
  );
}

// ─── Main component ───────────────────────────────────────────────────────────

export function NotificationBell() {
  const [open, setOpen] = useState(false);

  // Server-side unread count (polling fallback)
  const { data: serverUnreadCount } = useUnreadCount();

  // Zustand store unread count (updated by real-time Echo events)
  const storeUnreadCount = useNotificationStore((s) => s.unreadCount);
  const storeNotifications = useNotificationStore((s) => s.notifications);

  // Use whichever is higher to avoid showing 0 when the store has data
  const displayCount = Math.max(
    storeUnreadCount,
    serverUnreadCount ?? 0,
  );

  // Fetch recent notifications for the dropdown (5 most recent, unread first)
  const { data: notificationsData, isLoading } = useNotifications({
    per_page: 10,
    page: 1,
  });

  // Merge store notifications (from real-time events) with fetched data.
  // Store items take precedence since they are the most recent.
  const fetchedList = notificationsData?.data ?? [];
  const mergedMap = new Map(
    [...fetchedList, ...storeNotifications].map((n) => [n.id, n]),
  );
  // Deduplicate and sort by created_at desc, take top 10
  const displayList = Array.from(mergedMap.values())
    .sort(
      (a, b) =>
        new Date(b.created_at).getTime() - new Date(a.created_at).getTime(),
    )
    .slice(0, 10);

  const { mutate: markAllAsRead, isPending: isMarkingAll } = useMarkAllAsRead();

  // Sync store count with server count when the dropdown opens
  const setUnreadCount = useNotificationStore((s) => s.setUnreadCount);
  useEffect(() => {
    if (open && serverUnreadCount !== undefined) {
      // Only lower the count — never override Echo-driven increments upward
      if (serverUnreadCount > storeUnreadCount) {
        setUnreadCount(serverUnreadCount);
      }
    }
  }, [open, serverUnreadCount, storeUnreadCount, setUnreadCount]);

  const handleMarkAllAsRead = () => {
    markAllAsRead(undefined, {
      onSuccess: () => setOpen(false),
    });
  };

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger
        className={cn(
          'relative inline-flex h-9 w-9 items-center justify-center rounded-lg border border-transparent',
          'text-sm font-medium transition-all outline-none',
          'hover:bg-muted hover:text-foreground',
          'focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50',
          'aria-expanded:bg-muted',
        )}
        aria-label={
          displayCount > 0
            ? `Notifications — ${displayCount} unread`
            : 'Notifications'
        }
        aria-haspopup="dialog"
      >
        <Bell className="h-5 w-5" aria-hidden="true" />
        <UnreadBadge count={displayCount} />
      </PopoverTrigger>

      <PopoverContent
        className="w-96 p-0 shadow-lg"
        align="end"
        sideOffset={8}
        role="dialog"
        aria-label="Notifications"
      >
        {/* Header */}
        <div className="flex items-center justify-between px-4 py-3 border-b border-border">
          <h2 className="text-sm font-semibold">Notifications</h2>
          <div className="flex items-center gap-2">
            {displayCount > 0 && (
              <Button
                variant="ghost"
                size="sm"
                className="h-7 px-2 text-xs text-muted-foreground hover:text-foreground"
                onClick={handleMarkAllAsRead}
                disabled={isMarkingAll}
                aria-label="Mark all notifications as read"
              >
                {isMarkingAll ? 'Marking…' : 'Mark all read'}
              </Button>
            )}
          </div>
        </div>

        {/* Notification list */}
        {isLoading ? (
          <NotificationSkeleton />
        ) : displayList.length === 0 ? (
          <div className="flex flex-col items-center justify-center py-10 text-center">
            <Bell className="h-8 w-8 text-muted-foreground/40 mb-2" aria-hidden="true" />
            <p className="text-sm text-muted-foreground">No notifications yet</p>
          </div>
        ) : (
          <ScrollArea className="max-h-[360px]">
            <div role="list" aria-label="Recent notifications">
              {displayList.map((notification) => (
                <div key={notification.id} role="listitem">
                  <NotificationItem notification={notification} compact />
                </div>
              ))}
            </div>
          </ScrollArea>
        )}

        {/* Footer */}
        <div className="border-t border-border px-4 py-2.5">
          <a
            href="/notifications"
            className="block text-center text-xs font-medium text-primary hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1 rounded"
            onClick={() => setOpen(false)}
          >
            View all notifications
          </a>
        </div>
      </PopoverContent>
    </Popover>
  );
}
