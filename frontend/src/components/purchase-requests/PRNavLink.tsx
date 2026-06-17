"use client"

/**
 * PRNavLink.
 *
 * Renders the "Purchase Requests" nav link for roles that can access
 * the Procurement section (all authenticated roles).
 *
 * Validates: Requirements 5.2, 22.5
 */

import { useAuthStore } from "@/store/authStore"

export function PRNavLink() {
  const isAuthenticated = useAuthStore((s) => s.isAuthenticated)

  if (!isAuthenticated) return null

  return (
    <a
      href="/purchase-requests"
      className="rounded-md px-3 py-1.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
    >
      Purchase Requests
    </a>
  )
}
