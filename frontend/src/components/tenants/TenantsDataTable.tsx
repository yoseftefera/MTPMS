"use client"

/**
 * Tenants DataTable component (System_Admin).
 *
 * Features:
 * - Paginated list of tenants with server-side pagination
 * - Search by name/subdomain/tenant_code (debounced)
 * - Filter by status
 * - Columns: name, subdomain, tenant_code, admin_email, status badge, created_at, actions
 * - Row actions: View, Suspend (active only), Reactivate (suspended only)
 *
 * Validates: Requirements 1.6, 1.8
 */

import { useState, useCallback, useTransition } from "react"
import { Search, Plus, MoreHorizontal, Eye, PauseCircle, PlayCircle, RefreshCw } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import {
  Table,
  TableHeader,
  TableBody,
  TableRow,
  TableHead,
  TableCell,
} from "@/components/ui/table"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { Skeleton } from "@/components/ui/skeleton"
import { TenantStatusBadge } from "./TenantStatusBadge"
import { SuspendTenantDialog } from "./SuspendTenantDialog"
import { ReactivateTenantDialog } from "./ReactivateTenantDialog"
import { useTenants } from "@/hooks/useTenants"
import type { Tenant } from "@/types/models.types"

// ─── Skeleton rows ────────────────────────────────────────────────────────────

function SkeletonRows() {
  return (
    <>
      {Array.from({ length: 8 }).map((_, i) => (
        <TableRow key={i} aria-hidden="true">
          <TableCell>
            <div className="space-y-1.5">
              <Skeleton className="h-4 w-36" />
              <Skeleton className="h-3 w-24" />
            </div>
          </TableCell>
          <TableCell><Skeleton className="h-4 w-28" /></TableCell>
          <TableCell><Skeleton className="h-4 w-16" /></TableCell>
          <TableCell><Skeleton className="h-4 w-40" /></TableCell>
          <TableCell><Skeleton className="h-5 w-20 rounded-full" /></TableCell>
          <TableCell><Skeleton className="h-4 w-24" /></TableCell>
          <TableCell />
        </TableRow>
      ))}
    </>
  )
}

// ─── Row actions ──────────────────────────────────────────────────────────────

function RowActions({
  tenant,
  onSuspend,
  onReactivate,
}: {
  tenant: Tenant
  onSuspend: (tenant: Tenant) => void
  onReactivate: (tenant: Tenant) => void
}) {
  const [menuOpen, setMenuOpen] = useState(false)

  return (
    <div className="relative flex justify-end">
      <Button
        variant="ghost"
        size="icon-sm"
        aria-label={`Actions for ${tenant.name}`}
        aria-haspopup="menu"
        aria-expanded={menuOpen}
        onClick={() => setMenuOpen((v) => !v)}
      >
        <MoreHorizontal className="size-4" />
      </Button>

      {menuOpen && (
        <>
          {/* backdrop */}
          <div className="fixed inset-0 z-10" onClick={() => setMenuOpen(false)} />
          <div
            role="menu"
            className="absolute right-0 top-full z-20 mt-1 w-44 rounded-lg border border-border bg-popover py-1 text-sm shadow-md"
          >
            <a
              role="menuitem"
              href={`/admin/tenants/${tenant.id}`}
              className="flex w-full items-center gap-2 px-3 py-2 text-left transition-colors hover:bg-muted"
              onClick={() => setMenuOpen(false)}
            >
              <Eye className="size-3.5 text-muted-foreground" />
              View Details
            </a>

            {tenant.status === "active" && (
              <button
                role="menuitem"
                className="flex w-full items-center gap-2 px-3 py-2 text-left text-amber-700 transition-colors hover:bg-amber-50 dark:text-amber-400 dark:hover:bg-amber-900/20"
                onClick={() => {
                  setMenuOpen(false)
                  onSuspend(tenant)
                }}
              >
                <PauseCircle className="size-3.5" />
                Suspend
              </button>
            )}

            {tenant.status === "suspended" && (
              <button
                role="menuitem"
                className="flex w-full items-center gap-2 px-3 py-2 text-left text-green-700 transition-colors hover:bg-green-50 dark:text-green-400 dark:hover:bg-green-900/20"
                onClick={() => {
                  setMenuOpen(false)
                  onReactivate(tenant)
                }}
              >
                <PlayCircle className="size-3.5" />
                Reactivate
              </button>
            )}
          </div>
        </>
      )}
    </div>
  )
}

// ─── Filter options ───────────────────────────────────────────────────────────

const STATUS_FILTER_OPTIONS = [
  { value: "all", label: "All Statuses" },
  { value: "active", label: "Active" },
  { value: "suspended", label: "Suspended" },
  { value: "deactivated", label: "Deactivated" },
]

// ─── Main component ───────────────────────────────────────────────────────────

export function TenantsDataTable() {
  const [search, setSearch] = useState("")
  const [debouncedSearch, setDebouncedSearch] = useState("")
  const [statusFilter, setStatusFilter] = useState("all")
  const [page, setPage] = useState(1)

  const [, startTransition] = useTransition()

  // Dialogs
  const [suspendTarget, setSuspendTarget] = useState<Tenant | null>(null)
  const [reactivateTarget, setReactivateTarget] = useState<Tenant | null>(null)

  // Debounce search
  const handleSearchChange = useCallback((value: string) => {
    setSearch(value)
    const t = setTimeout(() => {
      startTransition(() => {
        setDebouncedSearch(value)
        setPage(1)
      })
    }, 400)
    return () => clearTimeout(t)
  }, [])

  const queryParams = {
    page,
    per_page: 15,
    search: debouncedSearch || undefined,
    status: statusFilter !== "all" ? (statusFilter as Tenant["status"]) : undefined,
    sort_by: "created_at",
    sort_dir: "desc" as const,
  }

  const { data, isLoading, isError, refetch } = useTenants(queryParams)

  const tenants = data?.data ?? []
  const meta = data?.meta

  const formatDate = (iso: string) =>
    new Intl.DateTimeFormat("en-US", { month: "short", day: "numeric", year: "numeric" }).format(
      new Date(iso),
    )

  return (
    <div className="space-y-4">
      {/* Toolbar */}
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex flex-1 flex-col gap-2 sm:flex-row sm:items-center">
          {/* Search */}
          <div className="relative w-full sm:max-w-xs">
            <Search className="absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
            <Input
              placeholder="Search tenants…"
              value={search}
              onChange={(e) => handleSearchChange(e.target.value)}
              className="pl-8"
              aria-label="Search tenants"
            />
          </div>

          {/* Status filter */}
          <div className="relative w-full sm:w-44">
            <Select
              value={statusFilter}
              onValueChange={(val) => {
                setStatusFilter(val)
                setPage(1)
              }}
            >
              <SelectTrigger aria-label="Filter by status">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {STATUS_FILTER_OPTIONS.map((opt) => (
                  <SelectItem key={opt.value} value={opt.value}>
                    {opt.label}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
        </div>

        <a href="/admin/tenants/new">
          <Button className="shrink-0 w-full sm:w-auto">
            <Plus className="size-4" />
            Register Tenant
          </Button>
        </a>
      </div>

      {/* Table */}
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
            {isLoading && <SkeletonRows />}

            {isError && (
              <TableRow>
                <TableCell colSpan={7} className="py-10 text-center text-muted-foreground">
                  <p className="mb-2">Failed to load tenants.</p>
                  <Button variant="outline" size="sm" onClick={() => refetch()}>
                    <RefreshCw className="size-3.5" />
                    Retry
                  </Button>
                </TableCell>
              </TableRow>
            )}

            {!isLoading && !isError && tenants.length === 0 && (
              <TableRow>
                <TableCell colSpan={7} className="py-10 text-center text-muted-foreground">
                  {debouncedSearch || statusFilter !== "all"
                    ? "No tenants match the current filters."
                    : "No tenants registered yet."}
                </TableCell>
              </TableRow>
            )}

            {!isLoading &&
              !isError &&
              tenants.map((tenant) => (
                <TableRow key={tenant.id}>
                  <TableCell>
                    <div className="flex items-center gap-3">
                      <div
                        aria-hidden="true"
                        className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-xs font-semibold text-primary"
                      >
                        {tenant.name.slice(0, 2).toUpperCase()}
                      </div>
                      <p className="text-sm font-medium leading-none">{tenant.name}</p>
                    </div>
                  </TableCell>

                  <TableCell className="text-sm text-muted-foreground font-mono">
                    {tenant.subdomain}
                  </TableCell>

                  <TableCell className="text-sm font-mono font-medium">
                    {tenant.tenant_code}
                  </TableCell>

                  <TableCell className="text-sm text-muted-foreground">
                    {tenant.admin_email}
                  </TableCell>

                  <TableCell>
                    <TenantStatusBadge status={tenant.status} />
                  </TableCell>

                  <TableCell className="text-sm text-muted-foreground">
                    {formatDate(tenant.created_at)}
                  </TableCell>

                  <TableCell>
                    <RowActions
                      tenant={tenant}
                      onSuspend={setSuspendTarget}
                      onReactivate={setReactivateTarget}
                    />
                  </TableCell>
                </TableRow>
              ))}
          </TableBody>
        </Table>
      </div>

      {/* Pagination */}
      {meta && meta.last_page > 1 && (
        <div className="flex items-center justify-between text-sm text-muted-foreground">
          <span>
            Showing {meta.from ?? 0}–{meta.to ?? 0} of {meta.total} tenants
          </span>
          <div className="flex items-center gap-2">
            <Button
              variant="outline"
              size="sm"
              onClick={() => setPage((p) => Math.max(1, p - 1))}
              disabled={page === 1 || isLoading}
            >
              Previous
            </Button>
            <span className="px-1 text-xs">
              Page {meta.current_page} of {meta.last_page}
            </span>
            <Button
              variant="outline"
              size="sm"
              onClick={() => setPage((p) => Math.min(meta.last_page, p + 1))}
              disabled={page === meta.last_page || isLoading}
            >
              Next
            </Button>
          </div>
        </div>
      )}

      {/* Confirmation modals */}
      {suspendTarget && (
        <SuspendTenantDialog
          tenant={suspendTarget}
          open={!!suspendTarget}
          onOpenChange={(open) => !open && setSuspendTarget(null)}
        />
      )}

      {reactivateTarget && (
        <ReactivateTenantDialog
          tenant={reactivateTarget}
          open={!!reactivateTarget}
          onOpenChange={(open) => !open && setReactivateTarget(null)}
        />
      )}
    </div>
  )
}
