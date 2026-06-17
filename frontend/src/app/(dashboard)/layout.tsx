/**
 * Dashboard layout.
 *
 * Wraps all authenticated dashboard pages. Provides a simple
 * top navigation and main content area.
 *
 * - "Budgets" link: Finance_Officer and Tenant_Admin only
 * - "Purchase Requests" link: all authenticated users
 * - "Approvals" link: Tenant_Admin, Procurement_Officer, Finance_Officer, Store_Manager, Committee_Member
 * - "Workflows" link: Tenant_Admin only
 * - "Suppliers" link: all authenticated users
 * - "Purchase Orders" link: Procurement_Officer, Tenant_Admin, Supplier
 * - "Contracts" link: Procurement_Officer, Tenant_Admin
 */

import { BudgetNavLink } from "@/components/budgets/BudgetNavLink"
import { PRNavLink } from "@/components/purchase-requests/PRNavLink"
import { ApprovalsNavLink } from "@/components/approvals/ApprovalsNavLink"
import { ApprovalWorkflowNavLink } from "@/components/approval-workflows/ApprovalWorkflowNavLink"
import { SupplierNavLink } from "@/components/suppliers/SupplierNavLink"
import { TenderNavLink } from "@/components/tenders/TenderNavLink"
import { PONavLink } from "@/components/purchase-orders/PONavLink"
import { ContractNavLink } from "@/components/contracts/ContractNavLink"
import { GRNNavLink } from "@/components/goods-receipts/GRNNavLink"
import { InventoryNavLink } from "@/components/inventory/InventoryNavLink"

export default function DashboardLayout({
  children,
}: {
  children: React.ReactNode
}) {
  return (
    <div className="flex min-h-screen flex-col bg-background">
      {/* Top nav bar */}
      <header className="sticky top-0 z-40 flex h-14 items-center gap-4 border-b border-border bg-card px-6 shadow-xs">
        <a href="/" className="flex items-center gap-2">
          <span className="text-base font-semibold tracking-tight">PMP</span>
        </a>
        <nav
          className="flex flex-1 items-center gap-1 text-sm"
          aria-label="Main navigation"
        >
          <a
            href="/dashboard"
            className="rounded-md px-3 py-1.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
          >
            Dashboard
          </a>
          <a
            href="/users"
            className="rounded-md px-3 py-1.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
          >
            Users
          </a>
          <a
            href="/departments"
            className="rounded-md px-3 py-1.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
          >
            Departments
          </a>

          {/* Finance section — visible to Finance_Officer and Tenant_Admin only */}
          <BudgetNavLink />

          {/* Procurement section — visible to all authenticated users */}
          <PRNavLink />

          {/* Approvals section — visible to approver roles */}
          <ApprovalsNavLink />

          {/* Supplier management — all authenticated users */}
          <SupplierNavLink />

          {/* Tender management — Procurement_Officer / Tenant_Admin / Committee_Member see /tenders,
              Supplier sees /tenders/open */}
          <TenderNavLink />

          {/* Purchase Orders — Procurement_Officer, Tenant_Admin, Supplier */}
          <PONavLink />

          {/* Contract management — Procurement_Officer, Tenant_Admin */}
          <ContractNavLink />

          {/* Goods Receipts — Store_Manager, Tenant_Admin, Committee_Member */}
          <GRNNavLink />

          {/* Inventory — Store_Manager, Tenant_Admin, Procurement_Officer */}
          <InventoryNavLink />

          {/* Workflow configuration — Tenant_Admin only */}
          <ApprovalWorkflowNavLink />
        </nav>
      </header>

      {/* Page content */}
      <main className="flex-1 px-6 py-6">{children}</main>
    </div>
  )
}
