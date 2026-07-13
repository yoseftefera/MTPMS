"use client"

/**
 * Invoice list page.
 *
 * Features:
 * - Paginated table: invoice number, supplier, PO/contract ref, total, paid,
 *   status badge, due date
 * - Status filter, date range filters, supplier filter
 * - "Submit Invoice" button (Supplier role only)
 * - Loading skeleton, error state with retry
 * - Row links to invoice detail page
 *
 * Validates: Requirements 14.1, 14.10, 22.6
 */

import { useState } from "react"
import Link from "next/link"
import { Plus, RefreshCw, Eye } from "lucide-react"
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
import { InvoiceStatusBadge } from "@/components/invoices/InvoiceStatusBadge"
import { CreateInvoiceDialog } from "@/components/invoices/CreateInvoiceDialog"
import { useInvoices } from "@/hooks/useInvoices"
import { useAuthStore } from "@/store/authStore"
import { formatCurrency } from "@/lib/utils"
import type { InvoiceFilters, InvoiceFilterStatus, InvoiceDetail } from "@/types/invoice"

// ─── Constants ────────────────────────────────────────────────────────────────

const STATUS_OPTIONS: { value: InvoiceFilterStatus; label: string }[] = [
  { value: "",                 label: "All Statuses"    },
  { value: "pending_approval", label: "Pending Approval" },
  { value: "approved",         label: "Approved"        },
  { value: "rejected",         label: "Rejected"        },
  { value: "partially_paid",   label: "Partially Paid"  },
  { value: "paid",             label: "Paid"            },
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
  visible: {
    opacity: 1,
    y: 0,
    transition: { duration: 0.25, ease: "easeOut" as const },
  },
}

// ─── Page ─────────────────────────────────────────────────────────────────────

export default function InvoicesPage() {
  const role = useAuthStore((s) => s.role)
  const canCreate = role === "Supplier"

  const [createOpen, setCreateOpen] = useState(false)
  const [status, setStatus] = useState<InvoiceFilterStatus>("")
  const [dateFrom, setDateFrom] = useState("")
  const [dateTo, setDateTo] = useState("")
  const [page, setPage] = useState(1)

  const filters: InvoiceFilters = {
    ...(status && { status }),
    ...(dateFrom && { date_from: dateFrom }),
    ...(dateTo && { date_to: dateTo }),
    page,
    per_page: 15,
  }

  const { data, isLoading, isError, refetch } = useInvoices(filters)

  const invoices: InvoiceDetail[] = data?.data ?? []
  const meta = data?.meta

  function handleStatusChange(val: string) {
    setStatus(val === "all" ? "" : (val as InvoiceFilterStatus))
    setPage(1)
  }

  function handleDateFromChange(e: React.ChangeEvent<HTMLInputElement>) {
    setDateFrom(e.target.value)
    setPage(1)
  }

  function handleDateToChange(e: React.ChangeEvent<HTMLInputElement>) {
    setDateTo(e.target.value)
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
        <h1 className="text-2xl font-semibold tracking-tight">Invoices</h1>
        <p className="mt-1 text-sm text-muted-foreground">
          Manage and track supplier invoices for payment processing.
        </p>
      </div>

      {/* Toolbar */}
      <div className="flex flex-col gap-3">
        <div className="flex flex-wrap gap-2">
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

          {/* Date range filters */}
          <div className="flex items-center gap-2">
            <Input
              type="date"
              value={dateFrom}
              onChange={handleDateFromChange}
              aria-label="Filter from date"
              className="w-40"
              placeholder="From date"
            />
            <span className="text-sm text-muted-foreground">to</span>
            <Input
              type="date"
              value={dateTo}
              onChange={handleDateToChange}
              aria-label="Filter to date"
              className="w-40"
              placeholder="To date"
            />
          </div>
        </div>

        {/* Actions */}
        <div className="flex justify-end">
          {canCreate && (
            <Button
              onClick={() => setCreateOpen(true)}
              aria-label="Submit new invoice"
            >
              <Plus className="size-4" aria-hidden="true" />
              Submit Invoice
            </Button>
          )}
        </div>
      </div>

      {/* Table */}
      <div className="rounded-xl border border-border bg-card">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Invoice #</TableHead>
              <TableHead>Supplier</TableHead>
              <TableHead>PO / Contract Ref</TableHead>
              <TableHead className="text-right">Total</TableHead>
              <TableHead className="text-right">Paid</TableHead>
              <TableHead>Status</TableHead>
              <TableHead>Due Date</TableHead>
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
                  <p className="mb-3 text-sm">Failed to load invoices.</p>
                  <Button variant="outline" size="sm" onClick={() => refetch()}>
                    <RefreshCw className="size-3.5" aria-hidden="true" />
                    Retry
                  </Button>
                </TableCell>
              </TableRow>
            )}

            {!isLoading && !isError && invoices.length === 0 && (
              <TableRow>
                <TableCell
                  colSpan={8}
                  className="py-12 text-center text-muted-foreground"
                >
                  <p className="text-sm">No invoices found.</p>
                  {canCreate && (
                    <button
                      className="mt-1 text-sm text-primary underline-offset-2 hover:underline"
                      onClick={() => setCreateOpen(true)}
                    >
                      Submit your first invoice.
                    </button>
                  )}
                </TableCell>
              </TableRow>
            )}

            {!isLoading &&
              !isError &&
              invoices.map((inv) => {
                const refLabel = inv.purchase_order
                  ? inv.purchase_order.po_number
                  : inv.contract
                    ? inv.contract.contract_number
                    : "—"

                return (
                  <TableRow key={inv.id} className="group">
                    <TableCell className="font-mono text-sm font-medium">
                      {inv.invoice_number}
                    </TableCell>
                    <TableCell className="text-sm">
                      {inv.supplier?.organization_name ?? "—"}
                    </TableCell>
                    <TableCell className="font-mono text-sm text-muted-foreground">
                      {refLabel}
                    </TableCell>
                    <TableCell className="text-right tabular-nums font-medium">
                      {formatCurrency(inv.total_amount, inv.currency)}
                    </TableCell>
                    <TableCell className="text-right tabular-nums text-muted-foreground text-sm">
                      {formatCurrency(inv.paid_amount, inv.currency)}
                    </TableCell>
                    <TableCell>
                      <InvoiceStatusBadge status={inv.status} />
                    </TableCell>
                    <TableCell className="text-sm text-muted-foreground">
                      {inv.due_date
                        ? new Date(inv.due_date).toLocaleDateString()
                        : "—"}
                    </TableCell>
                    <TableCell className="text-center">
                      <Link
                        href={`/invoices/${inv.id}`}
                        className="inline-flex items-center justify-center rounded-lg border-transparent p-1.5 text-sm text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                        aria-label={`View invoice ${inv.invoice_number}`}
                      >
                        <Eye className="size-4" aria-hidden="true" />
                        <span className="sr-only">View</span>
                      </Link>
                    </TableCell>
                  </TableRow>
                )
              })}
          </TableBody>
        </Table>
      </div>

      {/* Pagination */}
      {meta && meta.last_page > 1 && (
        <div className="flex items-center justify-between text-sm text-muted-foreground">
          <span>
            Showing {meta.from ?? 0}–{meta.to ?? 0} of {meta.total} invoices
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

      {/* Create dialog — Supplier only */}
      <CreateInvoiceDialog
        open={createOpen}
        onOpenChange={setCreateOpen}
        onSuccess={() => refetch()}
      />
    </motion.div>
  )
}
