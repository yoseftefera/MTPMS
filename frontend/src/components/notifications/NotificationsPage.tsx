'use client';

/**
 * NotificationsPage — full notification history with filters.
 *
 * Features:
 * - Filter by event type (select)
 * - Filter by read status (all / unread / read)
 * - Filter by date range (from / to)
 * - Paginated results table
 * - "Mark all as read" action in the page header
 * - Individual mark-as-read on each row (optimistic)
 * - Loading skeleton and empty state
 *
 * Validates: Requirements 15.7, 22.5
 */

import { useState } from 'react';
import { Bell, Check } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import {
  useNotifications,
  useMarkAsRead,
  useMarkAllAsRead,
} from '@/hooks/useNotifications';
import type { NotificationFilters, NotificationReadFilter } from '@/types/notification';
import type { Notification } from '@/types/models.types';

// ─── Event type options ───────────────────────────────────────────────────────

const EVENT_TYPE_OPTIONS = [
  { value: '', label: 'All types' },
  { value: 'purchase_request_submitted', label: 'Purchase Request Submitted' },
  { value: 'purchase_request_status_changed', label: 'PR Status Changed' },
  { value: 'tender_published', label: 'Tender Published' },
  { value: 'bid_deadline_approaching', label: 'Bid Deadline Approaching' },
  { value: 'bid_evaluation_completed', label: 'Bid Evaluation Completed' },
  { value: 'purchase_order_issued', label: 'Purchase Order Issued' },
  { value: 'purchase_order_status_changed', label: 'PO Status Changed' },
  { value: 'goods_receipt_created', label: 'Goods Receipt Created' },
  { value: 'invoice_submitted', label: 'Invoice Submitted' },
  { value: 'invoice_status_changed', label: 'Invoice Status Changed' },
  { value: 'payment_processed', label: 'Payment Processed' },
  { value: 'budget_threshold_reached', label: 'Budget Threshold Alert' },
  { value: 'contract_renewal_alert', label: 'Contract Renewal Alert' },
  { value: 'account_locked', label: 'Account Locked' },
  { value: 'low_stock_alert', label: 'Low Stock Alert' },
];

const PER_PAGE_OPTIONS = [10, 25, 50];

// ─── Relative time ────────────────────────────────────────────────────────────

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
  return new Date(isoString).toLocaleDateString(undefined, {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  });
}

// ─── Row ──────────────────────────────────────────────────────────────────────

interface NotificationRowProps {
  notification: Notification;
}

function NotificationRow({ notification }: NotificationRowProps) {
  const { mutate: markAsRead, isPending } = useMarkAsRead();

  const typeLabel =
    EVENT_TYPE_OPTIONS.find((o) => o.value === notification.event_type)?.label ??
    notification.event_type;

  return (
    <TableRow
      className={cn(
        !notification.is_read && 'bg-primary/5 hover:bg-primary/10',
      )}
    >
      <TableCell className="w-10 pl-4">
        <span
          className={cn(
            'block h-2 w-2 rounded-full',
            notification.is_read ? 'bg-muted' : 'bg-primary',
          )}
          aria-label={notification.is_read ? 'Read' : 'Unread'}
        />
      </TableCell>

      <TableCell>
        <Badge variant="outline" className="text-xs font-normal">
          {typeLabel}
        </Badge>
      </TableCell>

      <TableCell>
        <p
          className={cn(
            'text-sm',
            !notification.is_read && 'font-medium',
          )}
        >
          {notification.title}
        </p>
        <p className="text-xs text-muted-foreground mt-0.5 line-clamp-1">
          {notification.message}
        </p>
      </TableCell>

      <TableCell className="text-xs text-muted-foreground whitespace-nowrap">
        {relativeTime(notification.created_at)}
      </TableCell>

      <TableCell className="pr-4 text-right">
        {!notification.is_read && (
          <Button
            variant="ghost"
            size="sm"
            className="h-7 px-2 text-xs"
            onClick={() => markAsRead(notification.id)}
            disabled={isPending}
            aria-label={`Mark "${notification.title}" as read`}
          >
            <Check className="h-3.5 w-3.5 mr-1" aria-hidden="true" />
            Mark read
          </Button>
        )}
      </TableCell>
    </TableRow>
  );
}

// ─── Skeleton rows ────────────────────────────────────────────────────────────

function SkeletonRows() {
  return (
    <>
      {Array.from({ length: 8 }).map((_, i) => (
        <TableRow key={i}>
          <TableCell className="pl-4"><Skeleton className="h-2 w-2 rounded-full" /></TableCell>
          <TableCell><Skeleton className="h-5 w-28" /></TableCell>
          <TableCell>
            <Skeleton className="h-4 w-48 mb-1" />
            <Skeleton className="h-3 w-36" />
          </TableCell>
          <TableCell><Skeleton className="h-3 w-16" /></TableCell>
          <TableCell />
        </TableRow>
      ))}
    </>
  );
}

// ─── Main component ───────────────────────────────────────────────────────────

export function NotificationsPage() {
  const [filters, setFilters] = useState<NotificationFilters>({
    page: 1,
    per_page: 25,
    event_type: '',
    is_read: '' as NotificationReadFilter,
    date_from: '',
    date_to: '',
  });

  const { data, isLoading } = useNotifications({
    ...filters,
    // Don't send empty strings to the API
    event_type: filters.event_type || undefined,
    is_read: (filters.is_read as string) ? filters.is_read : undefined,
    date_from: filters.date_from || undefined,
    date_to: filters.date_to || undefined,
  });

  const { mutate: markAllAsRead, isPending: isMarkingAll } = useMarkAllAsRead();

  const notifications = data?.data ?? [];
  const meta = data?.meta;
  const totalPages = meta?.last_page ?? 1;
  const currentPage = meta?.current_page ?? 1;

  const updateFilter = <K extends keyof NotificationFilters>(
    key: K,
    value: NotificationFilters[K],
  ) => {
    setFilters((prev) => ({ ...prev, [key]: value, page: 1 }));
  };

  const handlePageChange = (page: number) => {
    setFilters((prev) => ({ ...prev, page }));
  };

  return (
    <div className="space-y-6">
      {/* Page header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold">Notifications</h1>
          <p className="text-sm text-muted-foreground mt-1">
            Your full notification history
          </p>
        </div>
        <Button
          variant="outline"
          size="sm"
          onClick={() => markAllAsRead()}
          disabled={isMarkingAll}
          aria-label="Mark all notifications as read"
        >
          <Check className="h-4 w-4 mr-2" aria-hidden="true" />
          {isMarkingAll ? 'Marking…' : 'Mark all as read'}
        </Button>
      </div>

      {/* Filters */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 p-4 rounded-lg border border-border bg-card">
        {/* Event type */}
        <div className="space-y-1.5">
          <Label htmlFor="filter-event-type" className="text-xs">Event Type</Label>
          <Select
            value={filters.event_type ?? ''}
            onValueChange={(v) => updateFilter('event_type', v)}
          >
            <SelectTrigger id="filter-event-type" className="h-9">
              <SelectValue placeholder="All types" />
            </SelectTrigger>
            <SelectContent>
              {EVENT_TYPE_OPTIONS.map((opt) => (
                <SelectItem key={opt.value} value={opt.value === '' ? '__all__' : opt.value}>
                  {opt.label}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>

        {/* Read status */}
        <div className="space-y-1.5">
          <Label htmlFor="filter-read-status" className="text-xs">Status</Label>
          <Select
            value={(filters.is_read as string) || '__all__'}
            onValueChange={(v) =>
              updateFilter('is_read', (v === '__all__' ? '' : v) as NotificationReadFilter)
            }
          >
            <SelectTrigger id="filter-read-status" className="h-9">
              <SelectValue placeholder="All" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="__all__">All</SelectItem>
              <SelectItem value="unread">Unread only</SelectItem>
              <SelectItem value="read">Read only</SelectItem>
            </SelectContent>
          </Select>
        </div>

        {/* Date from */}
        <div className="space-y-1.5">
          <Label htmlFor="filter-date-from" className="text-xs">From</Label>
          <Input
            id="filter-date-from"
            type="date"
            className="h-9"
            value={filters.date_from ?? ''}
            onChange={(e) => updateFilter('date_from', e.target.value)}
            aria-label="Filter notifications from date"
          />
        </div>

        {/* Date to */}
        <div className="space-y-1.5">
          <Label htmlFor="filter-date-to" className="text-xs">To</Label>
          <Input
            id="filter-date-to"
            type="date"
            className="h-9"
            value={filters.date_to ?? ''}
            onChange={(e) => updateFilter('date_to', e.target.value)}
            aria-label="Filter notifications to date"
          />
        </div>
      </div>

      {/* Table */}
      <div className="rounded-lg border border-border overflow-hidden">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead className="w-10 pl-4 sr-only">Status</TableHead>
              <TableHead className="w-44">Type</TableHead>
              <TableHead>Notification</TableHead>
              <TableHead className="w-28">Time</TableHead>
              <TableHead className="w-28 text-right pr-4">Action</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {isLoading ? (
              <SkeletonRows />
            ) : notifications.length === 0 ? (
              <TableRow>
                <TableCell colSpan={5}>
                  <div className="flex flex-col items-center justify-center py-16 text-center">
                    <Bell
                      className="h-10 w-10 text-muted-foreground/30 mb-3"
                      aria-hidden="true"
                    />
                    <p className="text-sm font-medium text-muted-foreground">
                      No notifications found
                    </p>
                    <p className="text-xs text-muted-foreground/70 mt-1">
                      Try adjusting your filters
                    </p>
                  </div>
                </TableCell>
              </TableRow>
            ) : (
              notifications.map((n) => (
                <NotificationRow key={n.id} notification={n} />
              ))
            )}
          </TableBody>
        </Table>
      </div>

      {/* Pagination */}
      {!isLoading && totalPages > 1 && (
        <div className="flex items-center justify-between text-sm text-muted-foreground">
          <div className="flex items-center gap-2">
            <span>Rows per page:</span>
            <Select
              value={String(filters.per_page ?? 25)}
              onValueChange={(v) => updateFilter('per_page', Number(v))}
            >
              <SelectTrigger className="h-7 w-16 text-xs">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {PER_PAGE_OPTIONS.map((n) => (
                  <SelectItem key={n} value={String(n)}>
                    {n}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          <div className="flex items-center gap-1">
            <Button
              variant="outline"
              size="sm"
              className="h-7 px-2"
              onClick={() => handlePageChange(currentPage - 1)}
              disabled={currentPage <= 1}
              aria-label="Previous page"
            >
              ←
            </Button>
            <span className="px-2">
              Page {currentPage} of {totalPages}
            </span>
            <Button
              variant="outline"
              size="sm"
              className="h-7 px-2"
              onClick={() => handlePageChange(currentPage + 1)}
              disabled={currentPage >= totalPages}
              aria-label="Next page"
            >
              →
            </Button>
          </div>
        </div>
      )}

      {/* Total count */}
      {!isLoading && meta && (
        <p className="text-xs text-muted-foreground">
          {meta.total} notification{meta.total !== 1 ? 's' : ''} total
        </p>
      )}
    </div>
  );
}
