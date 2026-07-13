/**
 * Approval Workflows management page.
 *
 * Accessible at /approval-workflows (within the dashboard route group).
 * Tenant_Admin can view, create, edit, and deactivate approval workflows here.
 *
 * Validates: Requirements 6.8, 22.7
 */

import { WorkflowsDataTable } from "@/components/approval-workflows/WorkflowsDataTable"
import { SectionErrorBoundary } from "@/components/ui/SectionErrorBoundary"

export const metadata = {
  title: "Approval Workflows — PMP",
  description: "Configure multi-level approval workflows for procurement documents.",
}

export default function ApprovalWorkflowsPage() {
  return (
    <div className="space-y-6">
      {/* Page header */}
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">Approval Workflows</h1>
        <p className="mt-1 text-sm text-muted-foreground">
          Configure multi-level approval workflows for procurement documents.
        </p>
      </div>

      {/* Data table (client component) — wrapped with error boundary */}
      <SectionErrorBoundary title="Approval workflows table">
        <WorkflowsDataTable />
      </SectionErrorBoundary>
    </div>
  )
}
