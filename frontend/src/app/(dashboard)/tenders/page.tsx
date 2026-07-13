/**
 * Tender list page.
 *
 * Accessible at /tenders (within the dashboard route group).
 * Procurement_Officer / Tenant_Admin / Committee_Member can view and manage tenders.
 *
 * Validates: Requirements 8.1, 8.3, 22.6, 22.7
 */

import { TendersDataTable } from "@/components/tenders/TendersDataTable"
import { SectionErrorBoundary } from "@/components/ui/SectionErrorBoundary"

export const metadata = {
  title: "Tenders — PMP",
  description: "Manage tenders and bid submissions.",
}

export default function TendersPage() {
  return (
    <div className="space-y-6">
      {/* Page header */}
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">Tenders</h1>
        <p className="mt-1 text-sm text-muted-foreground">
          Manage tenders and bid submissions.
        </p>
      </div>

      {/* Data table (client component) — wrapped with error boundary */}
      <SectionErrorBoundary title="Tenders table">
        <TendersDataTable />
      </SectionErrorBoundary>
    </div>
  )
}
