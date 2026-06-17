"use client"

/**
 * Supplier nav link — visible to all authenticated users.
 * Validates: Requirements 7.6, 7.7, 22.6
 */

import { useAuthStore } from "@/store/authStore"

export function SupplierNavLink() {
  const isAuthenticated = useAuthStore((s) => s.isAuthenticated)

  if (!isAuthenticated) return null

  return (
    <a
      href="/suppliers"
      className="rounded-md px-3 py-1.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
    >
      Suppliers
    </a>
  )
}
