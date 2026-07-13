"use client"

/**
 * InvoiceStatusBadge — colour-coded badge for an InvoiceStatus value.
 *
 * Validates: Requirements 14.1, 22.6
 */

import { Badge } from "@/components/ui/badge"
import type { InvoiceStatus } from "@/types/invoice"

type BadgeVariant =
  | "default"
  | "secondary"
  | "success"
  | "destructive"
  | "warning"
  | "outline"
  | "locked"

const STATUS_CONFIG: Record<InvoiceStatus, { label: string; variant: BadgeVariant }> = {
  pending_approval: { label: "Pending Approval", variant: "warning"     },
  approved:         { label: "Approved",         variant: "success"     },
  rejected:         { label: "Rejected",         variant: "destructive" },
  partially_paid:   { label: "Partially Paid",   variant: "locked"      },
  paid:             { label: "Paid",             variant: "success"     },
  // backend may return these legacy values
  submitted:        { label: "Submitted",        variant: "secondary"   },
  under_review:     { label: "Under Review",     variant: "default"     },
}

export function InvoiceStatusBadge({ status }: { status: InvoiceStatus | string }) {
  const config = (STATUS_CONFIG as Record<string, { label: string; variant: BadgeVariant }>)[status] ?? {
    label: status,
    variant: "outline" as BadgeVariant,
  }
  return (
    <Badge variant={config.variant} aria-label={`Status: ${config.label}`}>
      {config.label}
    </Badge>
  )
}
