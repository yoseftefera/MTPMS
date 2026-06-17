/**
 * ContractStatusBadge — color-coded ShadCN badge for contract statuses.
 *
 * Status color map:
 *   draft       → gray
 *   active      → green
 *   terminated  → red
 *   expired     → slate
 *   pending_bond / renewed → amber / blue
 *
 * Validates: Requirements 11.1, 22.6
 */

import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import type { ContractStatus } from '@/types/contract';

interface ContractStatusBadgeProps {
  status: ContractStatus;
}

const STATUS_CONFIG: Record<
  ContractStatus,
  { label: string; className: string }
> = {
  draft: {
    label: 'Draft',
    className: 'bg-gray-100 text-gray-700 border-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:border-gray-700',
  },
  active: {
    label: 'Active',
    className: 'bg-green-100 text-green-700 border-green-200 dark:bg-green-900/30 dark:text-green-400 dark:border-green-800',
  },
  terminated: {
    label: 'Terminated',
    className: 'bg-red-100 text-red-700 border-red-200 dark:bg-red-900/30 dark:text-red-400 dark:border-red-800',
  },
  expired: {
    label: 'Expired',
    className: 'bg-slate-100 text-slate-600 border-slate-200 dark:bg-slate-800 dark:text-slate-400 dark:border-slate-700',
  },
  pending_bond: {
    label: 'Pending Bond',
    className: 'bg-amber-100 text-amber-700 border-amber-200 dark:bg-amber-900/30 dark:text-amber-400 dark:border-amber-800',
  },
  renewed: {
    label: 'Renewed',
    className: 'bg-blue-100 text-blue-700 border-blue-200 dark:bg-blue-900/30 dark:text-blue-400 dark:border-blue-800',
  },
};

export function ContractStatusBadge({ status }: ContractStatusBadgeProps) {
  const config = STATUS_CONFIG[status] ?? {
    label: status,
    className: 'bg-gray-100 text-gray-700 border-gray-200',
  };

  return (
    <Badge
      variant="outline"
      className={cn('capitalize', config.className)}
      aria-label={`Contract status: ${config.label}`}
    >
      {config.label}
    </Badge>
  );
}
