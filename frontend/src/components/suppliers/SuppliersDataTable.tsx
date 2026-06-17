"use client"

/**
 * Suppliers DataTable component.
 *
 * Features:
 * - Paginated list of suppliers with server-side pagination
 * - Search by organization name (debounced)
 * - Filter by status and business category
 * - Status badges with appropriate colors
 * - Role-gated actions: Approve, Reject (pending_verification only);
 *   Blacklist (active only) — Procurement_Officer / Tenant_Admin
 * - Link to supplier detail page
 *
 * Validates: Requirements 7.6, 7.7, 22.6
 */

import { useState, useCallback, useTransition } from "react"
import { Search, Eye, RefreshCw } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Skeleton } from "@/components/ui/skeleton"
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
import { SupplierStatusBadge } from "./SupplierStatusBadge"
import { BlacklistSupplierDialog } from "./BlacklistSupplierDialog"
import { RejectSupplierDialog } from "./RejectSupplierDialog"
import { useSuppliers, useApproveSupplier } from "@/hooks/useSuppliers"
import { useAuthStore } from "@/store/authStore"
import { BUSINESS_CATEGORIES } from "@/lib/validations/suppliers"
import type { Supplier, SupplierStatus } from "@/types/models.types"

// ─── Skeleton rows ────────────────────────────────────────────────────────────

function SkeletonRows() {
  return (
    <>
      {Array.from({ length: 8 }).map((_, i) => (
        <TableRow key={i}>
          {Array.from({ length: 6 }).map((_, j) => (
            <TableCell key={j}>
              <Skeleton className="h-4 w-24" />
            </TableCell>
          ))}
        </TableRow>
      ))}
    </>
  )
}

// ─── Row actions ──────────────────────────────────────────────────────────────

const PRIVILEGED_ROLES = ["Procurement_Officer", "Tenant_Admin"]

function RowActions({
  supplier,
  canAct,
  onReject,
  onBlacklist,
}: {
  supplier: Supplier
  canAct: boolean
  onReject: (supplier: Supplier) => void
  onBlacklist: (supplier: Supplier) => void
}) {
  const approve = useApproveSupplier()

  return (
    <div className="flex items-center justify-end gap-1">
      {/* View detail */}
      <a
        href={`/suppliers/${supplier.id}`}
        className="inline-flex items-center justify-center rounded-lg p-1.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
        aria-label={`View supplier ${supplier.organization_name}`}
      >
        <Eye className="size-4" aria-hidden="true" />
        <span className="sr-only">View</span>
      </a>

      {/* Role-gated actions */}
      {canAct && supplier.status === "pending_verification" && (
        <>
          <Button
            size="sm"
            variant="outline"
            className="h-7 text-xs text-green-700 border-green-200 hover:bg-green-50 dark:text-green-400 dark:border-green-900 dark:hover:bg-green-900/20"
            disabled={approve.isPending}
            onClick={() => approve.mutate(supplier.id)}
            aria-label={`Approve ${supplier.organization_name}`}
          >
            {approve.isPending ? "Approving…" : "Approve"}
          </Button>
          <Button
            size="sm"
            variant="outline"
            className="h-7 text-xs text-destructive border-destructive/30 hover:bg-destructive/5"
            onClick={() => onReject(supplier)}
            aria-label={`Reject ${supplier.organization_name}`}
          >
            Reject
          </Button>
        </>
      )}

      {canAct && supplier.status === "active" && (
        <Button
          size="sm"
          variant="outline"
          className="h-7 text-xs text-destructive border-destructive/30 hover:bg-destructive/5"
          onClick={() => onBlacklist(supplier)}
          aria-label={`Blacklist ${supplier.organization_name}`}
        >
          Blacklist
        </Button>
      )}
    </div>
  )
}

// ─── Filter options ───────────────────────────────────────────────────────────

const STATUS_OPTIONS: { value: SupplierStatus | "all"; label: string }[] = [
  { value: "all", label: "All Statuses" },
  { value: "pending_verification", label: "Pending Verification" },
  { value: "active", label: "Active" },
  { value: "inactive", label: "Inactive" },
  { value: "blacklisted", label: "Blacklisted" },
]

// ─── Main component ───────────────────────────────────────────────────────────

export function SuppliersDataTable() {
  const role = useAuthStore((s) => s.role)
  const canAct = role !== null && PRIVILEGED_ROLES.includes(role)

  const [search, setSearch] = useState("")
  const [debouncedSearch, setDebouncedSearch] = useState("")
  const [statusFilter, setStatusFilter] = useState<SupplierStatus | "all">("all")
  const [categoryFilter, setCategoryFilter] = useState("all")
  const [page, setPage] = useState(1)

  const [, startTransition] = useTransition()

  // Dialogs
  const [rejectTarget, setRejectTarget] = useState<Supplier | null>(null)
  const [blacklistTarget, setBlacklistTarget] = useState<Supplier | null>(null)

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
    status: statusFilter !== "all" ? statusFilter : undefined,
    business_category: categoryFilter !== "all" ? categoryFilter : undefined,
    sort_by: "created_at",
    sort_dir: "desc" as const,
  }

  const { data, isLoading, isError, refetch } = useSuppliers(queryParams)

  const suppliers = data?.data ?? []
  const meta = data?.meta

  const formatDate = (iso: string) =>
    new Intl.DateTimeFormat("en-US", {
      month: "short",
      day: "numeric",
      year: "numeric",
    }).format(new Date(iso))

  return (
    <div className="space-y-4">
      {/* Toolbar */}
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:flex-wrap">
        {/* Search */}
        <div className="relative min-w-[200px] flex-1 sm:max-w-xs">
          <Search
            className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
            aria-hidden="true"
          />
          <Input
            placeholder="Search by organization name…"
            value={search}
            onChange={(e) => handleSearchChange(e.target.value)}
            className="pl-9"
            aria-label="Search suppliers"
          />
        </div>

        {/* Status filter */}
        <Select
          value={statusFilter}
          onValueChange={(val) => {
            setStatusFilter(val as SupplierStatus | "all")
            setPage(1)
          }}
        >
          <SelectTrigger className="w-52" aria-label="Filter by status">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {STATUS_OPTIONS.map((opt) => (
              <SelectItem key={opt.value} value={opt.value}>
                {opt.label}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>

        {/* Category filter */}
        <Select
          value={categoryFilter}
          onValueChange={(val) => {
            setCategoryFilter(val)
            setPage(1)
          }}
        >
          <SelectTrigger className="w-56" aria-label="Filter by business category">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">All Categories</SelectItem>
            {BUSINESS_CATEGORIES.map((cat) => (
              <SelectItem key={cat} value={cat}>
                {cat}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>

      {/* Table */}
      <div className="rounded-xl border border-border bg-card">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Organization</TableHead>
              <TableHead>Contact</TableHead>
              <TableHead>Category</TableHead>
              <TableHead>Status</TableHead>
              <TableHead>Registered</TableHead>
              <TableHead className="w-44 text-right">Actions</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {isLoading && <SkeletonRows />}

            {isError && (
              <TableRow>
                <TableCell colSpan={6} className="py-12 text-center text-muted-foreground">
                  <p className="mb-3 text-sm">Failed to load suppliers.</p>
                  <Button variant="outline" size="sm" onClick={() => refetch()}>
                    <RefreshCw className="size-3.5" aria-hidden="true" />
                    Retry
                  </Button>
                </TableCell>
              </TableRow>
            )}

            {!isLoading && !isError && suppliers.length === 0 && (
              <TableRow>
                <TableCell colSpan={6} className="py-12 text-center text-muted-foreground text-sm">
                  {debouncedSearch || statusFilter !== "all" || categoryFilter !== "all"
                    ? "No suppliers match the current filters."
                    : "No suppliers registered yet."}
                </TableCell>
              </TableRow>
            )}

            {!isLoading &&
              !isError &&
              suppliers.map((supplier) => (
                <TableRow key={supplier.id}>
                  <TableCell>
                    <p className="text-sm font-medium leading-none">
                      {supplier.organization_name}
                    </p>
                  </TableCell>

                  <TableCell>
                    <div>
                      <p className="text-sm">{supplier.contact_name}</p>
                      <p className="text-xs text-muted-foreground">{supplier.contact_email}</p>
                    </div>
                  </TableCell>

                  <TableCell className="text-sm text-muted-foreground">
                    {supplier.business_category}
                  </TableCell>

                  <TableCell>
                    <SupplierStatusBadge status={supplier.status} />
                  </TableCell>

                  <TableCell className="text-sm text-muted-foreground">
                    {formatDate(supplier.created_at)}
                  </TableCell>

                  <TableCell>
                    <RowActions
                      supplier={supplier}
                      canAct={canAct}
                      onReject={setRejectTarget}
                      onBlacklist={setBlacklistTarget}
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
            Showing {meta.from ?? 0}–{meta.to ?? 0} of {meta.total} suppliers
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

      {/* Dialogs */}
      {rejectTarget && (
        <RejectSupplierDialog
          supplier={rejectTarget}
          open={!!rejectTarget}
          onOpenChange={(open) => !open && setRejectTarget(null)}
          onSuccess={() => refetch()}
        />
      )}

      {blacklistTarget && (
        <BlacklistSupplierDialog
          supplier={blacklistTarget}
          open={!!blacklistTarget}
          onOpenChange={(open) => !open && setBlacklistTarget(null)}
          onSuccess={() => refetch()}
        />
      )}
    </div>
  )
}
