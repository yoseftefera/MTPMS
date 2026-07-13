/**
 * Pending Approvals page.
 *
 * Accessible at /approvals (within the dashboard route group).
 * Shows all documents pending approval for the current user.
 * Auto-refreshes every 30 seconds.
 *
 * Validates: Requirements 22.5, 22.7
 */

import { PendingApprovalsTable } from "@/components/approvals/PendingApprovalsTable"
import { SectionErrorBoundary } from "@/components/ui/SectionErrorBoundary"

export const metadata = {
  title: "Pending Approvals — PMP",
  description: "Review and action documents awaiting your approval.",
}

export default function ApprovalsPage() {
  return (
    <div className="space-y-6">
      {/* Page header */}
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">Pending Approvals</h1>
        <p className="mt-1 text-sm text-muted-foreground">
          Review and action documents awaiting your approval. This list refreshes
          automatically every 30 seconds.
        </p>
      </div>

      {/* Approvals table (client component) — wrapped with error boundary */}
      <SectionErrorBoundary title="Pending approvals table">
        <PendingApprovalsTable />
      </SectionErrorBoundary>
    </div>
  )
}
