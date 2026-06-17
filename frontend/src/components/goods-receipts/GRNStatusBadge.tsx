"use client"

/**
 * GRNStatusBadge — colour-coded Badge for a GoodsReceiptStatus value.
 *
 * Validates: Requirements 12.1, 22.6
 */

import { Badge } from "@/components/ui/badge"
import type { GoodsReceiptStatus } from "@/types/models.types"

type BadgeVariant =
  | "default"
  | "secondary"
  | "success"
  | "destructive"
  | "warning"
  | "outline"
  | "locked"

const STATUS_CONFIG: Record<
  GoodsReceiptStatus,
  { label: string; variant: BadgeVariant }
> = {
  pending_inspection:  { label: "Pending Inspection",  variant: "secondary"   },
  under_inspection:    { label: "Under Inspection",    variant: "warning"     },
  accepted:            { label: "Accepted",            variant: "success"     },
  partially_accepted:  { label: "Partially Accepted",  variant: "warning"     },
  rejected:            { label: "Rejected",            variant: "destructive" },
}

export function GRNStatusBadge({ status }: { status: GoodsReceiptStatus }) {
  const config = STATUS_CONFIG[status] ?? {
    label: status,
    variant: "outline" as BadgeVariant,
  }
  return (
    <Badge variant={config.variant} aria-label={`Status: ${config.label}`}>
      {config.label}
    </Badge>
  )
}
