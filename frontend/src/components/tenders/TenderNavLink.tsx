"use client"

/**
 * TenderNavLink.
 *
 * Renders the navigation links for the Tender & Bidding module.
 * - "Tenders" link: visible to Procurement_Officer, Tenant_Admin, Committee_Member
 * - "Open Tenders" link: visible to Supplier role
 *
 * Validates: Requirements 8.1, 8.3, 22.6
 */

import { useAuthStore } from "@/store/authStore"

const OFFICER_ROLES = ["Procurement_Officer", "Tenant_Admin", "Committee_Member"]

export function TenderNavLink() {
  const role = useAuthStore((s) => s.role)

  if (!role) return null

  if (OFFICER_ROLES.includes(role)) {
    return (
      <a
        href="/tenders"
        className="rounded-md px-3 py-1.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
      >
        Tenders
      </a>
    )
  }

  if (role === "Supplier") {
    return (
      <a
        href="/tenders/open"
        className="rounded-md px-3 py-1.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
      >
        Open Tenders
      </a>
    )
  }

  return null
}
