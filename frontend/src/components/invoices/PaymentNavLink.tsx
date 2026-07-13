"use client"

/**
 * PaymentNavLink — navigation link for the Payment Management module.
 *
 * Visible to: Finance_Officer, Tenant_Admin
 *
 * Validates: Requirements 14.5, 22.6
 */

import { useAuthStore } from "@/store/authStore"

const PAYMENT_ROLES = ["Finance_Officer", "Tenant_Admin"]

export function PaymentNavLink() {
  const role = useAuthStore((s) => s.role)

  if (!role || !PAYMENT_ROLES.includes(role)) return null

  return (
    <a
      href="/payments"
      className="rounded-md px-3 py-1.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
    >
      Payments
    </a>
  )
}
