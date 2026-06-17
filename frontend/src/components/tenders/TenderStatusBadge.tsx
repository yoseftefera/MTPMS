"use client"

/**
 * Reusable status badge for Tender entities.
 *
 * Validates: Requirements 8.1, 22.6
 */

import { Badge } from '@/components/ui/badge';
import type { TenderStatus } from '@/types/tender';

type BadgeVariant = 'default' | 'secondary' | 'success' | 'destructive' | 'warning' | 'outline' | 'locked';

const STATUS_CONFIG: Record<TenderStatus, { label: string; variant: BadgeVariant }> = {
  draft: { label: 'Draft', variant: 'secondary' },
  published: { label: 'Published', variant: 'success' },
  closed: { label: 'Closed', variant: 'locked' },
  awarded: { label: 'Awarded', variant: 'default' },
  cancelled: { label: 'Cancelled', variant: 'destructive' },
};

export function TenderStatusBadge({ status }: { status: TenderStatus }) {
  const config = STATUS_CONFIG[status] ?? { label: status, variant: 'outline' as BadgeVariant };
  return (
    <Badge variant={config.variant} aria-label={`Status: ${config.label}`}>
      {config.label}
    </Badge>
  );
}
