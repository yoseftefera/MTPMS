/**
 * Supplier list page.
 *
 * Accessible at /suppliers (within the dashboard route group).
 * Procurement_Officer / Tenant_Admin can view, approve, reject, and blacklist suppliers.
 * All authenticated users can browse the supplier list.
 *
 * Validates: Requirements 7.6, 7.7, 22.6
 */

import { SuppliersDataTable } from "@/components/suppliers/SuppliersDataTable"

export const metadata = {
  title: "Suppliers — PMP",
  description: "Manage supplier registrations, verifications, and performance.",
}

export default function SuppliersPage() {
  return (
    <div className="space-y-6">
      {/* Page header */}
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">Suppliers</h1>
        <p className="mt-1 text-sm text-muted-foreground">
          Manage supplier registrations, verify new applicants, and monitor supplier status.
        </p>
      </div>

      {/* Data table (client component) */}
      <SuppliersDataTable />
    </div>
  )
}
