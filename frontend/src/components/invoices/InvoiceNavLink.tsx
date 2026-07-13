"use client"

/**
 * InvoiceNavLink — navigation link for the Invoice & Payment Processing module.
 *
 * Visible to: Finance_Officer, Tenant_Admin, Supplier
 *
 * Validates: Requirements 14.1, 22.6
 */

import { useAuthStore } from "@/store/authStore"

const INVOICE_ROLES = ["Finance_Officer", "Tenant_Admin", "Supplier"]

export function InvoiceNavLink() {
  const role = useAuthStore((s) => s.role)

  if (!role || !INVOICE_ROLES.includes(role)) return null

  return (
    <a
      href="/invoices"
      className="rounded-md px-3 py-1.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
    >
      Invoices
    </a>
  )
}
