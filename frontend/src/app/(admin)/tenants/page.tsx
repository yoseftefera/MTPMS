/**
 * Tenant List Page — System_Admin.
 *
 * Displays a paginated, searchable, filterable DataTable of all tenants
 * registered on the platform. Wrapped in a SectionErrorBoundary with a
 * TableSkeleton during data fetch.
 *
 * Routes: /admin/tenants
 *
 * Validates: Requirements 1.6, 1.8
 */

import { Suspense } from "react";
import { Building2 } from "lucide-react";
import { TableSkeleton } from "@/components/ui/TableSkeleton";
import { SectionErrorBoundary } from "@/components/ui/SectionErrorBoundary";
import { TenantsDataTable } from "@/components/tenants/TenantsDataTable";
import {
  Table,
  TableBody,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";

// ─── Fallback while the client component hydrates ────────────────────────────

function TenantTableFallback() {
  return (
    <div className="rounded-xl border border-border bg-card">
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>Organization</TableHead>
            <TableHead>Subdomain</TableHead>
            <TableHead>Code</TableHead>
            <TableHead>Admin Email</TableHead>
            <TableHead>Status</TableHead>
            <TableHead>Registered</TableHead>
            <TableHead className="w-12" />
          </TableRow>
        </TableHeader>
        <TableBody>
          <TableSkeleton rows={8} columns={7} />
        </TableBody>
      </Table>
    </div>
  );
}

// ─── Page ─────────────────────────────────────────────────────────────────────

export default function TenantsPage() {
  return (
    <div className="space-y-6">
      {/* Page header */}
      <div className="flex items-start gap-4">
        <div
          className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary/10"
          aria-hidden="true"
        >
          <Building2 className="size-5 text-primary" />
        </div>
        <div>
          <h1 className="text-xl font-semibold tracking-tight">Tenants</h1>
          <p className="mt-0.5 text-sm text-muted-foreground">
            Manage all organizations registered on the platform.
          </p>
        </div>
      </div>

      {/* Data table wrapped in error boundary + Suspense */}
      <SectionErrorBoundary title="Tenant list">
        <Suspense fallback={<TenantTableFallback />}>
          <TenantsDataTable />
        </Suspense>
      </SectionErrorBoundary>
    </div>
  );
}
