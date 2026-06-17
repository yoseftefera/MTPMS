"use client"

/**
 * Reusable status badge for suppliers.
 * Maps SupplierStatus → label + badge color variant.
 */

import { Badge } from "@/components/ui/badge"
import type { SupplierStatus } from "@/types/models.types"

type BadgeVariant = "default" | "secondary" | "success" | "destructive" | "warning" | "outline" | "locked"

const STATUS_CONFIG: Record<SupplierStatus, { label: string; variant: BadgeVariant }> = {
  pending_verification: { label: "Pending Verification", variant: "warning" },
  active: { label: "Active", variant: "success" },
  inactive: { label: "Inactive", variant: "secondary" },
  blacklisted: { label: "Blacklisted", variant: "destructive" },
}

interface SupplierStatusBadgeProps {
  status: SupplierStatus
}

export function SupplierStatusBadge({ status }: SupplierStatusBadgeProps) {
  const config = STATUS_CONFIG[status] ?? { label: status, variant: "outline" as BadgeVariant }
  return (
    <Badge variant={config.variant} aria-label={`Supplier status: ${config.label}`}>
      {config.label}
    </Badge>
  )
}
