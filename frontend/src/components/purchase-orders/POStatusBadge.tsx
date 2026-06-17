"use client"

/**
 * POStatusBadge — renders a colour-coded Badge for a PurchaseOrderStatus value.
 *
 * Validates: Requirements 10.2, 22.6
 */

import { Badge } from "@/components/ui/badge"
import type { PurchaseOrderStatus } from "@/types/purchaseOrder"

type BadgeVariant =
  | "default"
  | "secondary"
  | "success"
  | "destructive"
  | "warning"
  | "outline"
  | "locked"

const STATUS_CONFIG: Record<
  PurchaseOrderStatus,
  { label: string; variant: BadgeVariant }
> = {
  draft:              { label: "Draft",              variant: "secondary"    },
  issued:             { label: "Issued",             variant: "default"      },
  accepted:           { label: "Accepted",           variant: "success"      },
  rejected:           { label: "Rejected",           variant: "destructive"  },
  partially_received: { label: "Partially Received", variant: "warning"      },
  fully_received:     { label: "Fully Received",     variant: "success"      },
  cancelled:          { label: "Cancelled",          variant: "locked"       },
  overdue:            { label: "Overdue",            variant: "warning"      },
}

export function POStatusBadge({ status }: { status: PurchaseOrderStatus }) {
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
