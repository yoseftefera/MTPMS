"use client"

/**
 * Reusable status badge for Bid entities.
 *
 * Validates: Requirements 8.3, 22.6
 */

import { Badge } from "@/components/ui/badge"
import type { BidStatus } from "@/types/tender"

type BadgeVariant =
  | "default"
  | "secondary"
  | "success"
  | "destructive"
  | "warning"
  | "outline"
  | "locked"

const STATUS_CONFIG: Record<BidStatus, { label: string; variant: BadgeVariant }> = {
  draft: { label: "Draft", variant: "secondary" },
  submitted: { label: "Submitted", variant: "warning" },
  under_evaluation: { label: "Under Evaluation", variant: "default" },
  won: { label: "Won", variant: "success" },
  lost: { label: "Lost", variant: "secondary" },
  disqualified: { label: "Disqualified", variant: "destructive" },
}

export function BidStatusBadge({ status }: { status: BidStatus }) {
  const config =
    STATUS_CONFIG[status] ?? { label: status, variant: "outline" as BadgeVariant }
  return (
    <Badge variant={config.variant} aria-label={`Bid status: ${config.label}`}>
      {config.label}
    </Badge>
  )
}
