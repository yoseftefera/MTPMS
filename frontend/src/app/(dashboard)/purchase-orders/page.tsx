"use client"

/**
 * Purchase Order list page.
 *
 * Features:
 * - Paginated ShadCN Table: PO number, supplier, department, total amount,
 *   status badge, delivery date, created date, actions
 * - Status filter: all, draft, issued, accepted, rejected, cancelled, overdue
 * - Search by PO number
 * - "Create PO" button (Procurement_Officer / Tenant_Admin only)
 * - Loading skeleton, error state with retry
 * - Links each row to the PO detail page
 *
 * Validates: Requirements 10.2, 22.6
 */

import { useState } from "react"
import Link from "next/link"
import { Plus, RefreshCw, Search, Eye } from "lucide-react"
import { motion } from "framer-motion"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
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
import { POStatusBadge } from "@/components/purchase-orders/POStatusBadge"
import { CreatePOForm } from "@/components/purchase-orders/CreatePOForm"
import { usePurchaseOrders } from "@/hooks/usePurchaseOrders"
import { useSuppliers } from "@/hooks/useSuppliers"
import { useAuthStore } from "@/store/authStore"
import { formatCurrency } from "@/lib/utils"
import type { POFilters, POFilterStatus, PurchaseOrderDetail } from "@/types/purchaseOrder"

// ─── Constants ────────────────────────────────────────────────────────────────

const CAN_CREATE_ROLES = ["Procurement_Officer", "Tenant_Admin"]

const STATUS_OPTIONS: { value: POFilterStatus; label: string }[] = [
  { value: "",               label: "All Statuses"        },
  { value: "draft",          label: "Draft"               },
  { value: "issued",         label: "Issued"              },
  { value: "accepted",       label: "Accepted"            },
  { value: "rejected",       label: "Rejected"            },
  { value: "cancelled",      label: "Cancelled"           },
  { value: "overdue",        label: "Overdue"             },
]

// Mock departments — replace with useDepartments() once available
const MOCK_DEPARTMENTS = [
  { id: "dept-placeholder-1", name: "Finance"    },
  { id: "dept-placeholder-2", name: "Operations" },
  { id: "dept-placeholder-3", name: "IT"         },
  { id: "dept-placeholder-4", name: "HR"         },
]

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

// ─── Framer Motion variants ───────────────────────────────────────────────────

const fadeIn = {
  hidden: { opacity: 0, y: 8 },
  visible: { opacity: 1, y: 0, transition: { duration: 0.25, ease: "easeOut" as const } },
}

// ─── Page ─────────────────────────────────────────────────────────────────────

export default function PurchaseOrdersPage() {
  const role = useAuthStore((s) => s.role)
  const canCreate = role !== null && CAN_CREATE_ROLES.includes(role)

  const [createOpen, setCreateOpen] = useState(false)
  const [search, setSearch] = useState("")
  const [status, setStatus] = useState<POFilterStatus>("")
  const [page, setPage] = useState(1)

  const filters: POFilters = {
    ...(search && { po_number: search }),
    ...(status && { status }),
    page,
    per_page: 15,
  }

  const { data, isLoading, isError, refetch } = usePurchaseOrders(filters)

  // Fetch active suppliers for the create form
  const { data: suppliersData } = useSuppliers({ status: "active", per_page: 200 })
  const activeSuppliers = suppliersData?.data ?? []

  const pos: PurchaseOrderDetail[] = data?.data ?? []
  const meta = data?.meta

  function handleSearchChange(e: React.ChangeEvent<HTMLInputElement>) {
    setSearch(e.target.value)
    setPage(1)
  }

  function handleStatusChange(val: string) {
    setStatus(val === "all" ? "" : (val as POFilterStatus))
    setPage(1)
  }

  return (
    <motion.div
      className="space-y-6"
      initial="hidden"
      animate="visible"
      variants={fadeIn}
    >
      {/* Page header */}
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">Purchase Orders</h1>
        <p className="mt-1 text-sm text-muted-foreground">
          Manage and track purchase orders issued to suppliers.
        </p>
      </div>

      {/* Toolbar */}
      <div className="flex flex-col gap-3">
        <div className="flex flex-wrap gap-2">
          {/* Search by PO number */}
          <div className="relative min-w-[200px] flex-1 sm:max-w-xs">
            <Search
              className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
              aria-hidden="true"
            />
            <Input
              placeholder="Search PO number…"
              value={search}
              onChange={handleSearchChange}
              className="pl-9"
              aria-label="Search purchase orders by PO number"
            />
          </div>

          {/* Status filter */}
          <Select value={status || "all"} onValueChange={handleStatusChange}>
            <SelectTrigger className="w-48" aria-label="Filter by status">
              <SelectValue placeholder="All Statuses" />
            </SelectTrigger>
            <SelectContent>
              {STATUS_OPTIONS.map((opt) => (
                <SelectItem
                  key={opt.value || "all"}
                  value={opt.value || "all"}
                >
                  {opt.label}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>

        {/* Actions */}
        <div className="flex justify-end">
          {canCreate && (
            <Button
              onClick={() => setCreateOpen(true)}
              aria-label="Create new purchase order"
            >
              <Plus className="size-4" aria-hidden="true" />
              Create PO
            </Button>
          )}
        </div>
      </div>

      {/* Table */}
      <div className="rounded-xl border border-border bg-card">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>PO Number</TableHead>
              <TableHead>Supplier</TableHead>
              <TableHead>Department</TableHead>
              <TableHead>Status</TableHead>
              <TableHead className="text-right">Total Amount</TableHead>
              <TableHead>Delivery Date</TableHead>
              <TableHead>Created</TableHead>
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
                  <p className="mb-3 text-sm">Failed to load purchase orders.</p>
                  <Button variant="outline" size="sm" onClick={() => refetch()}>
                    <RefreshCw className="size-3.5" aria-hidden="true" />
                    Retry
                  </Button>
                </TableCell>
              </TableRow>
            )}

            {!isLoading && !isError && pos.length === 0 && (
              <TableRow>
                <TableCell
                  colSpan={8}
                  className="py-12 text-center text-muted-foreground"
                >
                  <p className="text-sm">No purchase orders found.</p>
                  {canCreate && (
                    <button
                      className="mt-1 text-sm text-primary underline-offset-2 hover:underline"
                      onClick={() => setCreateOpen(true)}
                    >
                      Create your first purchase order.
                    </button>
                  )}
                </TableCell>
              </TableRow>
            )}

            {!isLoading &&
              !isError &&
              pos.map((po) => (
                <TableRow key={po.id} className="group">
                  <TableCell className="font-mono text-sm font-medium">
                    {po.po_number}
                  </TableCell>
                  <TableCell className="text-sm">
                    {po.supplier?.organization_name ?? "—"}
                  </TableCell>
                  <TableCell className="text-sm text-muted-foreground">
                    {po.department?.name ?? "—"}
                  </TableCell>
                  <TableCell>
                    <POStatusBadge status={po.status} />
                  </TableCell>
                  <TableCell className="text-right tabular-nums font-medium">
                    {formatCurrency(po.total_amount, po.currency)}
                  </TableCell>
                  <TableCell className="text-sm text-muted-foreground">
                    {po.required_delivery_date
                      ? new Date(po.required_delivery_date).toLocaleDateString()
                      : "—"}
                  </TableCell>
                  <TableCell className="text-sm text-muted-foreground">
                    {new Date(po.created_at).toLocaleDateString()}
                  </TableCell>
                  <TableCell className="text-center">
                    <Link
                      href={`/purchase-orders/${po.id}`}
                      className="inline-flex items-center justify-center rounded-lg border-transparent p-1.5 text-sm text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                      aria-label={`View purchase order ${po.po_number}`}
                    >
                      <Eye className="size-4" aria-hidden="true" />
                      <span className="sr-only">View</span>
                    </Link>
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
            Showing {meta.from ?? 0}–{meta.to ?? 0} of {meta.total} purchase
            orders
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
      <CreatePOForm
        open={createOpen}
        onOpenChange={setCreateOpen}
        suppliers={activeSuppliers}
        departments={MOCK_DEPARTMENTS}
        onSuccess={() => refetch()}
      />
    </motion.div>
  )
}
