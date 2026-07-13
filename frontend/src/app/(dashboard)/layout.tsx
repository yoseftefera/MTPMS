/**
 * Dashboard layout.
 *
 * Wraps all authenticated dashboard pages. Provides a sticky top
 * navigation bar and main content area.
 *
 * The top nav includes:
 * - Module navigation links (role-gated via individual NavLink components)
 * - ThemeToggle in the trailing slot (light/dark mode switch)
 * - NotificationBell in the trailing slot (bell icon + unread badge + dropdown)
 *
 * Navigation visibility rules:
 * - "Budgets" link: Finance_Officer and Tenant_Admin only
 * - "Purchase Requests" link: all authenticated users
 * - "Approvals" link: Tenant_Admin, Procurement_Officer, Finance_Officer, Store_Manager, Committee_Member
 * - "Workflows" link: Tenant_Admin only
 * - "Suppliers" link: all authenticated users
 * - "Purchase Orders" link: Procurement_Officer, Tenant_Admin, Supplier
 * - "Contracts" link: Procurement_Officer, Tenant_Admin
 * - "Invoices" link: Finance_Officer, Tenant_Admin, Supplier
 * - "Payments" link: Finance_Officer, Tenant_Admin
 *
 * Each page's content is wrapped by a PageErrorBoundary so rendering
 * errors in any page are caught and a retry action is presented instead
 * of crashing the entire layout.
 *
 * Validates: Requirements 15.6, 15.7, 22.2, 22.3, 22.4, 22.5, 22.7
 */

import { PageErrorBoundary } from '@/components/ui/PageErrorBoundary';
import { BudgetNavLink } from '@/components/budgets/BudgetNavLink';
import { PRNavLink } from '@/components/purchase-requests/PRNavLink';
import { ApprovalsNavLink } from '@/components/approvals/ApprovalsNavLink';
import { ApprovalWorkflowNavLink } from '@/components/approval-workflows/ApprovalWorkflowNavLink';
import { SupplierNavLink } from '@/components/suppliers/SupplierNavLink';
import { TenderNavLink } from '@/components/tenders/TenderNavLink';
import { PONavLink } from '@/components/purchase-orders/PONavLink';
import { ContractNavLink } from '@/components/contracts/ContractNavLink';
import { GRNNavLink } from '@/components/goods-receipts/GRNNavLink';
import { InventoryNavLink } from '@/components/inventory/InventoryNavLink';
import { InvoiceNavLink } from '@/components/invoices/InvoiceNavLink';
import { PaymentNavLink } from '@/components/invoices/PaymentNavLink';
import { AuditLogNavLink } from '@/components/audit-logs/AuditLogNavLink';
import { NotificationBell } from '@/components/notifications/NotificationBell';
import { ThemeToggle } from '@/components/ui/ThemeToggle';

export default function DashboardLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return (
    <div className="flex min-h-screen flex-col bg-background">
      {/* Skip-to-content link — visible on keyboard focus only (WCAG 2.4.1) */}
      <a
        href="#main-content"
        className="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50 focus:rounded-md focus:bg-background focus:px-4 focus:py-2 focus:text-sm focus:font-medium focus:text-foreground focus:shadow-md focus:outline-none focus:ring-2 focus:ring-ring"
      >
        Skip to content
      </a>

      {/* Top nav bar */}
      <header
        role="banner"
        className="sticky top-0 z-40 flex h-14 items-center gap-4 border-b border-border bg-card px-4 shadow-xs sm:px-6"
      >
        <a
          href="/"
          className="flex items-center gap-2 shrink-0 focus:outline-none focus-visible:ring-2 focus-visible:ring-ring rounded"
          aria-label="PMP — go to home"
        >
          <span className="text-base font-semibold tracking-tight">PMP</span>
        </a>

        {/* Scrollable nav — takes all available space */}
        <nav
          role="navigation"
          aria-label="Main navigation"
          className="flex flex-1 items-center gap-1 text-sm overflow-x-auto min-w-0"
        >
          <a
            href="/dashboard"
            className="rounded-md px-3 py-1.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground whitespace-nowrap focus:outline-none focus-visible:ring-2 focus-visible:ring-ring"
          >
            Dashboard
          </a>
          <a
            href="/users"
            className="rounded-md px-3 py-1.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground whitespace-nowrap focus:outline-none focus-visible:ring-2 focus-visible:ring-ring"
          >
            Users
          </a>
          <a
            href="/departments"
            className="rounded-md px-3 py-1.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground whitespace-nowrap focus:outline-none focus-visible:ring-2 focus-visible:ring-ring"
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

          {/* Invoices — Finance_Officer, Tenant_Admin, Supplier */}
          <InvoiceNavLink />

          {/* Payments — Finance_Officer, Tenant_Admin */}
          <PaymentNavLink />

          {/* Workflow configuration — Tenant_Admin only */}
          <ApprovalWorkflowNavLink />

          {/* Audit Logs — Tenant_Admin and System_Admin */}
          <AuditLogNavLink />
        </nav>

        {/* Trailing slot — theme toggle + notification bell */}
        <div className="flex items-center gap-1 shrink-0">
          <ThemeToggle />
          <NotificationBell />
        </div>
      </header>

      {/* Page content */}
      <main
        id="main-content"
        role="main"
        aria-label="Page content"
        className="flex-1 min-w-0 overflow-x-hidden px-4 py-6 sm:px-6"
      >
        {/* Inner container — constrains ultra-wide displays while staying fluid */}
        <div className="mx-auto w-full max-w-screen-2xl">
          {/*
           * PageErrorBoundary wraps every dashboard page so that any
           * unhandled rendering error results in an accessible retry UI
           * rather than a blank/crashed screen.
           * Validates: Requirements 22.5, 22.7
           */}
          <PageErrorBoundary>
            {children}
          </PageErrorBoundary>
        </div>
      </main>
    </div>
  );
}
