"use client";

/**
 * TenantStatusBadge — small badge rendering a Tenant's status with
 * appropriate colour variant.
 *
 * Validates: Requirements 1.6
 */

import { Badge } from "@/components/ui/badge";
import type { Tenant } from "@/types/models.types";

const STATUS_MAP: Record<
  Tenant["status"],
  { label: string; variant: "success" | "destructive" | "warning" | "secondary" }
> = {
  active: { label: "Active", variant: "success" },
  suspended: { label: "Suspended", variant: "warning" },
  deactivated: { label: "Deactivated", variant: "destructive" },
};

interface TenantStatusBadgeProps {
  status: Tenant["status"];
}

export function TenantStatusBadge({ status }: TenantStatusBadgeProps) {
  const { label, variant } = STATUS_MAP[status] ?? { label: status, variant: "secondary" };
  return <Badge variant={variant}>{label}</Badge>;
}
