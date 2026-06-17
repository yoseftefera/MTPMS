"use client"

/**
 * Purchase Request list page.
 *
 * Features:
 * - Filterable DataTable: PR Number, Title, Department, Status (Badge),
 *   Estimated Total, Submitted By, Date, Actions
 * - Filters: search (pr_number/title), department select, status select,
 *   date range (date_from/date_to)
 * - "Create PR" button for Department_Staff and above
 * - Pagination controls
 * - Loading skeleton + error boundary with retry
 *
 * Validates: Requirements 5.2, 5.5, 22.5, 22.6, 22.7
 */

import { useState } from "react"
import { Plus, RefreshCw, Search, Eye } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Badge } from "@/components/ui/badge"
import { Skeleton } from "@/components/ui/skeleton"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import {
  Table,
  TableHeader,
  TableBody,
  TableRow,
  TableHead,
  TableCell,
} from "@/components/ui/table"
import { CreatePRForm } from "@/components/purchase-requests/CreatePRForm"
import { usePurchaseRequests } from "@/hooks/usePurchaseRequest"
import { useAuthStore } from "@/store/authStore"
import { formatCurrency } from "@/lib/utils"
import type { PRFilters, PRStatus, PurchaseRequest } from "@/types/purchaseRequest"

// ─── Status badge helper ──────────────────────────────────────────────────────

type BadgeVariant = "default" | "secondary" | "success" | "destructive" | "warning" | "outline" | "locked"

const STATUS_CONFIG: Record<
  PRStatus,
  { label: string; variant: BadgeVariant }
> = {
  draft: { label: "Draft", variant: "secondary" },
  pending_approval: { label: "Pending Approval", variant: "warning" },
  approved: { label: "Approved", variant: "success" },
  rejected: { label: "Rejected", variant: "destructive" },
  cancelled: { label: "Cancelled", variant: "secondary" },
  revision_required: { label: "Revision Required", variant: "locked" },
}

function StatusBadge({ status }: { status: PRStatus }) {
  const config = STATUS_CONFIG[status] ?? { label: status, variant: "outline" as BadgeVariant }
  return (
    <Badge
      variant={config.variant}
      aria-label={`Status: ${config.label}`}
    >
      {config.label}
    </Badge>
  )
}

// ─── Skeleton rows ────────────────────────────────────────────────────────────

function SkeletonRows() {
  return (
    <>
      {Array.from({ length: 8 }).map((_, i) => (
        <TableRow key={i}>
          {Array.from({ length: 8 }).map((_, j) => (
            <TableCell key={j}>
              <Skeleton className="h-4 w-24" />
            </TableCell>
          ))}
        </TableRow>
      ))}
    </>
  )
}

// ─── Roles that can create PRs ────────────────────────────────────────────────

const CAN_CREATE_ROLES = [
  "Department_Staff",
  "Department_Manager",
  "Finance_Officer",
  "Procurement_Officer",
  "Tenant_Admin",
]

// ─── Mock departments — replace with useDepartments() once available ─────────

const MOCK_DEPARTMENTS = [
  { id: "dept-placeholder-1", name: "Finance" },
  { id: "dept-placeholder-2", name: "Operations" },
  { id: "dept-placeholder-3", name: "IT" },
  { id: "dept-placeholder-4", name: "HR" },
]

const STATUS_OPTIONS: { value: PRStatus | ""; label: string }[] = [
  { value: "", label: "All Statuses" },
  { value: "draft", label: "Draft" },
  { value: "pending_approval", label: "Pending Approval" },
  { value: "approved", label: "Approved" },
  { value: "rejected", label: "Rejected" },
  { value: "cancelled", label: "Cancelled" },
  { value: "revision_required", label: "Revision Required" },
]

// ─── Main component ───────────────────────────────────────────────────────────

export default function PurchaseRequestsPage() {
  const role = useAuthStore((s) => s.role)
  const canCreate = role !== null && CAN_CREATE_ROLES.includes(role)

  const [createOpen, setCreateOpen] = useState(false)
  const [search, setSearch] = useState("")
  const [departmentId, setDepartmentId] = useState("")
  const [status, setStatus] = useState<PRStatus | "">("")
  const [dateFrom, setDateFrom] = useState("")
  const [dateTo, setDateTo] = useState("")
  const [page, setPage] = useState(1)

  const filters: PRFilters = {
    ...(search && { search }),
    ...(departmentId && { department_id: departmentId }),
    ...(status && { status }),
    ...(dateFrom && { date_from: dateFrom }),
    ...(dateTo && { date_to: dateTo }),
    page,
    per_page: 15,
  }

  const { data, isLoading, isError, refetch } = usePurchaseRequests(filters)

  const prs: PurchaseRequest[] = data?.data ?? []
  const meta = data?.meta

  function handleSearchChange(e: React.ChangeEvent<HTMLInputElement>) {
    setSearch(e.target.value)
    setPage(1)
  }

  function handleDepartmentChange(val: string) {
    setDepartmentId(val === "all" ? "" : val)
    setPage(1)
  }

  function handleStatusChange(val: string) {
    setStatus(val === "all" ? "" : (val as PRStatus))
    setPage(1)
  }

  return (
    <div className="space-y-6">
      {/* Page header */}
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">Purchase Requests</h1>
        <p className="mt-1 text-sm text-muted-foreground">
          Create and manage procurement purchase requests.
        </p>
      </div>

      {/* Toolbar */}
      <div className="flex flex-col gap-3">
        {/* Filters row */}
        <div className="flex flex-wrap gap-2">
          {/* Search */}
          <div className="relative min-w-[200px] flex-1 sm:max-w-xs">
            <Search
              className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
              aria-hidden="true"
            />
            <Input
              placeholder="Search PR number or title…"
              value={search}
              onChange={handleSearchChange}
              className="pl-9"
              aria-label="Search purchase requests"
            />
          </div>

          {/* Department filter */}
          <Select value={departmentId || "all"} onValueChange={handleDepartmentChange}>
            <SelectTrigger className="w-44" aria-label="Filter by department">
              <SelectValue placeholder="All Departments" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All Departments</SelectItem>
              {MOCK_DEPARTMENTS.map((d) => (
                <SelectItem key={d.id} value={d.id}>
                  {d.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>

          {/* Status filter */}
          <Select value={status || "all"} onValueChange={handleStatusChange}>
            <SelectTrigger className="w-48" aria-label="Filter by status">
              <SelectValue placeholder="All Statuses" />
            </SelectTrigger>
            <SelectContent>
              {STATUS_OPTIONS.map((opt) => (
                <SelectItem key={opt.value || "all"} value={opt.value || "all"}>
                  {opt.label}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>

          {/* Date range */}
          <div className="flex items-center gap-1.5">
            <Input
              type="date"
              value={dateFrom}
              onChange={(e) => { setDateFrom(e.target.value); setPage(1) }}
              className="w-36"
              aria-label="Filter from date"
            />
            <span className="text-sm text-muted-foreground">—</span>
            <Input
              type="date"
              value={dateTo}
              onChange={(e) => { setDateTo(e.target.value); setPage(1) }}
              className="w-36"
              aria-label="Filter to date"
            />
          </div>
        </div>

        {/* Actions row */}
        <div className="flex justify-end">
          {canCreate && (
            <Button onClick={() => setCreateOpen(true)} aria-label="Create new purchase request">
              <Plus className="size-4" aria-hidden="true" />
              Create PR
            </Button>
          )}
        </div>
      </div>

      {/* Table */}
      <div className="rounded-xl border border-border bg-card">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>PR Number</TableHead>
              <TableHead>Title</TableHead>
              <TableHead>Department</TableHead>
              <TableHead>Status</TableHead>
              <TableHead className="text-right">Estimated Total</TableHead>
              <TableHead>Submitted By</TableHead>
              <TableHead>Date</TableHead>
              <TableHead className="w-20 text-center">Actions</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {isLoading && <SkeletonRows />}

            {isError && (
              <TableRow>
                <TableCell
                  colSpan={8}
                  className="py-12 text-center text-muted-foreground"
                >
                  <p className="mb-3 text-sm">Failed to load purchase requests.</p>
                  <Button variant="outline" size="sm" onClick={() => refetch()}>
                    <RefreshCw className="size-3.5" aria-hidden="true" />
                    Retry
                  </Button>
                </TableCell>
              </TableRow>
            )}

            {!isLoading && !isError && prs.length === 0 && (
              <TableRow>
                <TableCell
                  colSpan={8}
                  className="py-12 text-center text-muted-foreground"
                >
                  <p className="text-sm">No purchase requests found.</p>
                  {canCreate && (
                    <button
                      className="mt-1 text-sm text-primary underline-offset-2 hover:underline"
                      onClick={() => setCreateOpen(true)}
                    >
                      Create your first purchase request.
                    </button>
                  )}
                </TableCell>
              </TableRow>
            )}

            {!isLoading &&
              !isError &&
              prs.map((pr) => (
                <TableRow key={pr.id} className="group">
                  <TableCell className="font-mono text-sm font-medium">
                    {pr.pr_number}
                  </TableCell>
                  <TableCell className="max-w-[200px] truncate" title={pr.title}>
                    {pr.title}
                  </TableCell>
                  <TableCell className="text-sm text-muted-foreground">
                    {pr.department?.name ?? "—"}
                  </TableCell>
                  <TableCell>
                    <StatusBadge status={pr.status} />
                  </TableCell>
                  <TableCell className="text-right tabular-nums font-medium">
                    {formatCurrency(pr.estimated_total, pr.currency)}
                  </TableCell>
                  <TableCell className="text-sm text-muted-foreground">
                    {pr.submitter?.name ?? "—"}
                  </TableCell>
                  <TableCell className="text-sm text-muted-foreground">
                    {pr.submitted_at
                      ? new Date(pr.submitted_at).toLocaleDateString()
                      : new Date(pr.created_at).toLocaleDateString()}
                  </TableCell>
                  <TableCell className="text-center">
                    <a
                      href={`/purchase-requests/${pr.id}`}
                      className="inline-flex items-center justify-center rounded-lg border-transparent p-1.5 text-sm text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                      aria-label={`View purchase request ${pr.pr_number}`}
                    >
                      <Eye className="size-4" aria-hidden="true" />
                      <span className="sr-only">View</span>
                    </a>
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
            Showing {meta.from ?? 0}–{meta.to ?? 0} of {meta.total} purchase requests
          </span>
          <div className="flex items-center gap-2">
            <Button
              variant="outline"
              size="sm"
              disabled={page <= 1}
              onClick={() => setPage((p) => Math.max(1, p - 1))}
              aria-label="Previous page"
            >
              Previous
            </Button>
            <span className="px-2">
              Page {meta.current_page} of {meta.last_page}
            </span>
            <Button
              variant="outline"
              size="sm"
              disabled={page >= meta.last_page}
              onClick={() => setPage((p) => p + 1)}
              aria-label="Next page"
            >
              Next
            </Button>
          </div>
        </div>
      )}

      {/* Create dialog */}
      <CreatePRForm
        open={createOpen}
        onOpenChange={setCreateOpen}
        departments={MOCK_DEPARTMENTS}
        onSuccess={() => refetch()}
      />
    </div>
  )
}
