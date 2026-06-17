"use client"

/**
 * InventoryNavLink — navigation link for the Inventory module.
 *
 * Visible to: Store_Manager, Tenant_Admin, Procurement_Officer
 *
 * Validates: Requirements 12.8, 22.6
 */

import { useAuthStore } from "@/store/authStore"

const INVENTORY_ROLES = ["Store_Manager", "Tenant_Admin", "Procurement_Officer"]

export function InventoryNavLink() {
  const role = useAuthStore((s) => s.role)

  if (!role || !INVENTORY_ROLES.includes(role)) return null

  return (
    <a
      href="/inventory"
      className="rounded-md px-3 py-1.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
    >
      Inventory
    </a>
  )
}
