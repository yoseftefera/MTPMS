"use client"

/**
 * Goods Receipt list page.
 *
 * Features:
 * - Paginated table: GRN number, PO number, delivery note, status badge, received at
 * - Status filter dropdown
 * - "Create GRN" button (Store_Manager only)
 * - Loading skeleton, error state with retry
 * - Row links to the GRN detail page
 *
 * Validates: Requirements 12.1, 22.6
 */

import { useState } from "react"
import Link from "next/link"
import { Plus, RefreshCw, Eye } from "lucide-react"
import { motion } from "framer-motion"
import { Button } from "@/components/ui/button"
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
import { GRNStatusBadge } from "@/components/goods-receipts/GRNStatusBadge"
import { CreateGRNDialog } from "@/components/goods-receipts/CreateGRNDialog"
import { useGoodsReceipts } from "@/hooks/useGoodsReceipts"
import { useAuthStore } from "@/store/authStore"
import type { GRNFilters, GRNFilterStatus } from "@/types/goodsReceipt"

// ─── Constants ────────────────────────────────────────────────────────────────

const STATUS_OPTIONS: { value: GRNFilterStatus; label: string }[] = [
  { value: "",                   label: "All Statuses"       },
  { value: "pending_inspection", label: "Pending Inspection" },
  { value: "under_inspection",   label: "Under Inspection"   },
  { value: "accepted",           label: "Accepted"           },
  { value: "partially_accepted", label: "Partially Accepted" },
  { value: "rejected",           label: "Rejected"           },
]

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

// ─── Framer Motion variants ───────────────────────────────────────────────────

const fadeIn = {
  hidden: { opacity: 0, y: 8 },
  visible: { opacity: 1, y: 0, transition: { duration: 0.25, ease: "easeOut" as const } },
}

// ─── Page ─────────────────────────────────────────────────────────────────────

export default function GoodsReceiptsPage() {
  const role = useAuthStore((s) => s.role)
  const canCreate = role === "Store_Manager" || role === "Tenant_Admin"

  const [createOpen, setCreateOpen] = useState(false)
  const [status, setStatus] = useState<GRNFilterStatus>("")
  const [page, setPage] = useState(1)

  const filters: GRNFilters = {
    ...(status && { status }),
    page,
    per_page: 15,
  }

  const { data, isLoading, isError, refetch } = useGoodsReceipts(filters)

  const grns = data?.data ?? []
  const meta = data?.meta

  function handleStatusChange(val: string) {
    setStatus(val === "all" ? "" : (val as GRNFilterStatus))
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
        <h1 className="text-2xl font-semibold tracking-tight">Goods Receipts</h1>
        <p className="mt-1 text-sm text-muted-foreground">
          Record and manage goods received against purchase orders.
        </p>
      </div>

      {/* Toolbar */}
      <div className="flex flex-wrap items-center justify-between gap-3">
        {/* Status filter */}
        <Select value={status || "all"} onValueChange={handleStatusChange}>
          <SelectTrigger className="w-52" aria-label="Filter by status">
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

        {/* Create button */}
        <div className="flex items-center gap-2">
          <Button
            variant="outline"
            size="sm"
            onClick={() => refetch()}
            aria-label="Refresh goods receipts"
          >
            <RefreshCw className="size-4" aria-hidden="true" />
            Refresh
          </Button>
          {canCreate && (
            <Button
              onClick={() => setCreateOpen(true)}
              aria-label="Create new goods receipt"
            >
              <Plus className="size-4" aria-hidden="true" />
              Create GRN
            </Button>
          )}
        </div>
      </div>

      {/* Table */}
      <div className="rounded-xl border border-border bg-card">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>GRN Number</TableHead>
              <TableHead>PO Number</TableHead>
              <TableHead>Delivery Note</TableHead>
              <TableHead>Status</TableHead>
              <TableHead>Received At</TableHead>
              <TableHead className="w-20 text-center">Actions</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {isLoading && <SkeletonRows />}

            {isError && (
              <TableRow>
                <TableCell
                  colSpan={6}
                  className="py-12 text-center text-muted-foreground"
                >
                  <p className="mb-3 text-sm">Failed to load goods receipts.</p>
                  <Button variant="outline" size="sm" onClick={() => refetch()}>
                    <RefreshCw className="size-3.5" aria-hidden="true" />
                    Retry
                  </Button>
                </TableCell>
              </TableRow>
            )}

            {!isLoading && !isError && grns.length === 0 && (
              <TableRow>
                <TableCell
                  colSpan={6}
                  className="py-12 text-center text-muted-foreground"
                >
                  <p className="text-sm">No goods receipts found.</p>
                  {canCreate && (
                    <button
                      className="mt-1 text-sm text-primary underline-offset-2 hover:underline"
                      onClick={() => setCreateOpen(true)}
                    >
                      Create your first goods receipt.
                    </button>
                  )}
                </TableCell>
              </TableRow>
            )}

            {!isLoading &&
              !isError &&
              grns.map((grn) => (
                <TableRow key={grn.id} className="group">
                  <TableCell className="font-mono text-sm font-medium">
                    {grn.grn_number}
                  </TableCell>
                  <TableCell className="font-mono text-sm">
                    {grn.purchase_order?.po_number ?? "—"}
                  </TableCell>
                  <TableCell className="text-sm text-muted-foreground">
                    {grn.delivery_note_number}
                  </TableCell>
                  <TableCell>
                    <GRNStatusBadge status={grn.status} />
                  </TableCell>
                  <TableCell className="text-sm text-muted-foreground">
                    {grn.received_at
                      ? new Date(grn.received_at).toLocaleDateString()
                      : "—"}
                  </TableCell>
                  <TableCell className="text-center">
                    <Link
                      href={`/goods-receipts/${grn.id}`}
                      className="inline-flex items-center justify-center rounded-lg border-transparent p-1.5 text-sm text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                      aria-label={`View goods receipt ${grn.grn_number}`}
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
            Showing {meta.from ?? 0}–{meta.to ?? 0} of {meta.total} goods receipts
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

      {/* Create GRN dialog */}
      <CreateGRNDialog
        open={createOpen}
        onOpenChange={setCreateOpen}
        onSuccess={() => refetch()}
      />
    </motion.div>
  )
}
