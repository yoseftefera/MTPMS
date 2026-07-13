"use client"

/**
 * Payment management page.
 *
 * Sections:
 * 1. Payment Schedule — table of upcoming invoices due (invoice number,
 *    supplier, amount due, due date, days until due, urgency coloring)
 * 2. All Payments — paginated table with status filter
 *    "Record Payment" button per row → RecordPaymentDialog
 *
 * Validates: Requirements 14.5, 14.6, 14.7, 14.8, 22.6
 */

import { useState } from "react"
import { motion } from "framer-motion"
import { RefreshCw, CreditCard, CalendarClock } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Skeleton } from "@/components/ui/skeleton"
import { Badge } from "@/components/ui/badge"
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
import { RecordPaymentDialog } from "@/components/invoices/RecordPaymentDialog"
import { usePayments, usePaymentSchedule } from "@/hooks/usePayments"
import { formatCurrency } from "@/lib/utils"
import type { PaymentFilters, PaymentStatus, PaymentDetail, PaymentScheduleEntry } from "@/types/invoice"

// ─── Constants ────────────────────────────────────────────────────────────────

const PAYMENT_STATUS_OPTIONS: { value: PaymentStatus | ""; label: string }[] = [
  { value: "",          label: "All Statuses" },
  { value: "scheduled", label: "Scheduled"    },
  { value: "processed", label: "Processed"    },
  { value: "failed",    label: "Failed"       },
]

// ─── Urgency coloring for days until due ─────────────────────────────────────

function DaysUntilDueBadge({ days }: { days: number }) {
  if (days < 0) {
    return (
      <Badge variant="destructive" aria-label={`Overdue by ${Math.abs(days)} days`}>
        Overdue {Math.abs(days)}d
      </Badge>
    )
  }
  if (days <= 5) {
    return (
      <Badge variant="warning" aria-label={`Due in ${days} days`}>
        {days}d
      </Badge>
    )
  }
  return (
    <span className="text-sm text-muted-foreground" aria-label={`Due in ${days} days`}>
      {days}d
    </span>
  )
}

// ─── Payment status badge ─────────────────────────────────────────────────────

type BadgeVariant = "default" | "secondary" | "success" | "destructive" | "warning" | "outline"

const PAYMENT_STATUS_CONFIG: Record<PaymentStatus, { label: string; variant: BadgeVariant }> = {
  scheduled: { label: "Scheduled", variant: "warning"     },
  processed: { label: "Processed", variant: "success"     },
  failed:    { label: "Failed",    variant: "destructive" },
}

function PaymentStatusBadge({ status }: { status: PaymentStatus }) {
  const config = PAYMENT_STATUS_CONFIG[status] ?? { label: status, variant: "outline" as BadgeVariant }
  return (
    <Badge variant={config.variant} aria-label={`Status: ${config.label}`}>
      {config.label}
    </Badge>
  )
}

// ─── Skeleton rows ────────────────────────────────────────────────────────────

function SkeletonRows({ cols }: { cols: number }) {
  return (
    <>
      {Array.from({ length: 5 }).map((_, i) => (
        <TableRow key={i}>
          {Array.from({ length: cols }).map((_, j) => (
            <TableCell key={j}>
              <Skeleton className="h-4 w-20" />
            </TableCell>
          ))}
        </TableRow>
      ))}
    </>
  )
}

// ─── Framer Motion ────────────────────────────────────────────────────────────

const fadeIn = {
  hidden: { opacity: 0, y: 8 },
  visible: {
    opacity: 1,
    y: 0,
    transition: { duration: 0.25, ease: "easeOut" as const },
  },
}

// ─── Page ─────────────────────────────────────────────────────────────────────

export default function PaymentsPage() {
  // ── Payment schedule state ────────────────────────────────────────────────

  const {
    data: scheduleData,
    isLoading: scheduleLoading,
    isError: scheduleError,
    refetch: refetchSchedule,
  } = usePaymentSchedule()

  const scheduleEntries: PaymentScheduleEntry[] = scheduleData?.data ?? []

  // ── All payments state ────────────────────────────────────────────────────

  const [statusFilter, setStatusFilter] = useState<PaymentStatus | "">("")
  const [page, setPage] = useState(1)
  const [recordDialogOpen, setRecordDialogOpen] = useState(false)
  const [selectedPayment, setSelectedPayment] = useState<PaymentDetail | null>(null)

  const filters: PaymentFilters = {
    ...(statusFilter && { status: statusFilter }),
    page,
    per_page: 15,
  }

  const {
    data: paymentsData,
    isLoading: paymentsLoading,
    isError: paymentsError,
    refetch: refetchPayments,
  } = usePayments(filters)

  const payments: PaymentDetail[] = paymentsData?.data ?? []
  const meta = paymentsData?.meta

  function handleStatusChange(val: string) {
    setStatusFilter(val === "all" ? "" : (val as PaymentStatus))
    setPage(1)
  }

  function handleRecordClick(payment: PaymentDetail) {
    setSelectedPayment(payment)
    setRecordDialogOpen(true)
  }

  function handleRecordSuccess() {
    refetchPayments()
    refetchSchedule()
  }

  // ── Helpers ───────────────────────────────────────────────────────────────

  function formatPaymentMethod(method: string): string {
    return method.replace(/_/g, " ").replace(/\b\w/g, (c) => c.toUpperCase())
  }

  // ── Render ────────────────────────────────────────────────────────────────

  return (
    <motion.div
      className="space-y-8"
      initial="hidden"
      animate="visible"
      variants={fadeIn}
    >
      {/* Page header */}
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">Payment Management</h1>
        <p className="mt-1 text-sm text-muted-foreground">
          Track upcoming payments and record processed payments.
        </p>
      </div>

      {/* ── Payment Schedule ──────────────────────────────────────────────── */}
      <section aria-labelledby="payment-schedule-heading">
        <div className="mb-3 flex items-center gap-2">
          <CalendarClock className="size-5 text-muted-foreground" aria-hidden="true" />
          <h2 id="payment-schedule-heading" className="text-base font-semibold">
            Payment Schedule
          </h2>
        </div>

        <div className="rounded-xl border border-border bg-card">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Invoice #</TableHead>
                <TableHead>Supplier</TableHead>
                <TableHead className="text-right">Amount Due</TableHead>
                <TableHead>Due Date</TableHead>
                <TableHead>Days Until Due</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {scheduleLoading && <SkeletonRows cols={5} />}

              {scheduleError && (
                <TableRow>
                  <TableCell
                    colSpan={5}
                    className="py-12 text-center text-muted-foreground"
                  >
                    <p className="mb-3 text-sm">Failed to load payment schedule.</p>
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => refetchSchedule()}
                    >
                      <RefreshCw className="size-3.5" aria-hidden="true" />
                      Retry
                    </Button>
                  </TableCell>
                </TableRow>
              )}

              {!scheduleLoading && !scheduleError && scheduleEntries.length === 0 && (
                <TableRow>
                  <TableCell
                    colSpan={5}
                    className="py-10 text-center text-sm text-muted-foreground"
                  >
                    No upcoming payments due.
                  </TableCell>
                </TableRow>
              )}

              {!scheduleLoading &&
                !scheduleError &&
                scheduleEntries.map((entry) => (
                  <TableRow
                    key={entry.invoice_id}
                    className={
                      entry.days_until_due < 0
                        ? "bg-destructive/5"
                        : entry.days_until_due <= 5
                          ? "bg-amber-50 dark:bg-amber-950/20"
                          : ""
                    }
                  >
                    <TableCell className="font-mono text-sm font-medium">
                      {entry.invoice_number}
                    </TableCell>
                    <TableCell className="text-sm">{entry.supplier_name}</TableCell>
                    <TableCell className="text-right tabular-nums font-medium">
                      {formatCurrency(entry.amount_due, entry.currency)}
                    </TableCell>
                    <TableCell className="text-sm text-muted-foreground">
                      {new Date(entry.due_date).toLocaleDateString()}
                    </TableCell>
                    <TableCell>
                      <DaysUntilDueBadge days={entry.days_until_due} />
                    </TableCell>
                  </TableRow>
                ))}
            </TableBody>
          </Table>
        </div>
      </section>

      {/* ── All Payments ──────────────────────────────────────────────────── */}
      <section aria-labelledby="all-payments-heading">
        <div className="mb-3 flex items-center gap-2">
          <CreditCard className="size-5 text-muted-foreground" aria-hidden="true" />
          <h2 id="all-payments-heading" className="text-base font-semibold">
            All Payments
          </h2>
        </div>

        {/* Status filter */}
        <div className="mb-3 flex flex-wrap items-center gap-2">
          <Select value={statusFilter || "all"} onValueChange={handleStatusChange}>
            <SelectTrigger className="w-44" aria-label="Filter by payment status">
              <SelectValue placeholder="All Statuses" />
            </SelectTrigger>
            <SelectContent>
              {PAYMENT_STATUS_OPTIONS.map((opt) => (
                <SelectItem key={opt.value || "all"} value={opt.value || "all"}>
                  {opt.label}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>

        <div className="rounded-xl border border-border bg-card">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Invoice #</TableHead>
                <TableHead>Supplier</TableHead>
                <TableHead className="text-right">Amount</TableHead>
                <TableHead>Method</TableHead>
                <TableHead>Reference</TableHead>
                <TableHead>Scheduled</TableHead>
                <TableHead>Status</TableHead>
                <TableHead className="w-32 text-center">Actions</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {paymentsLoading && <SkeletonRows cols={8} />}

              {paymentsError && (
                <TableRow>
                  <TableCell
                    colSpan={8}
                    className="py-12 text-center text-muted-foreground"
                  >
                    <p className="mb-3 text-sm">Failed to load payments.</p>
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => refetchPayments()}
                    >
                      <RefreshCw className="size-3.5" aria-hidden="true" />
                      Retry
                    </Button>
                  </TableCell>
                </TableRow>
              )}

              {!paymentsLoading && !paymentsError && payments.length === 0 && (
                <TableRow>
                  <TableCell
                    colSpan={8}
                    className="py-10 text-center text-sm text-muted-foreground"
                  >
                    No payments found.
                  </TableCell>
                </TableRow>
              )}

              {!paymentsLoading &&
                !paymentsError &&
                payments.map((payment) => {
                  const canRecord = payment.status === "scheduled"
                  const displayAmount =
                    payment.amount_paid ?? payment.amount

                  return (
                    <TableRow key={payment.id} className="group">
                      <TableCell className="font-mono text-sm font-medium">
                        {payment.invoice?.invoice_number ?? "—"}
                      </TableCell>
                      <TableCell className="text-sm">
                        {payment.invoice?.supplier?.organization_name ?? "—"}
                      </TableCell>
                      <TableCell className="text-right tabular-nums font-medium">
                        {formatCurrency(
                          displayAmount,
                          payment.invoice?.currency ?? "USD",
                        )}
                      </TableCell>
                      <TableCell className="text-sm text-muted-foreground">
                        {payment.payment_method
                          ? formatPaymentMethod(payment.payment_method)
                          : "—"}
                      </TableCell>
                      <TableCell className="font-mono text-xs text-muted-foreground">
                        {payment.payment_reference ?? "—"}
                      </TableCell>
                      <TableCell className="text-sm text-muted-foreground">
                        {payment.scheduled_date
                          ? new Date(payment.scheduled_date).toLocaleDateString()
                          : "—"}
                      </TableCell>
                      <TableCell>
                        <PaymentStatusBadge status={payment.status} />
                      </TableCell>
                      <TableCell className="text-center">
                        {canRecord && (
                          <Button
                            size="sm"
                            variant="outline"
                            onClick={() => handleRecordClick(payment)}
                            aria-label={`Record payment for invoice ${payment.invoice?.invoice_number ?? ""}`}
                          >
                            Record
                          </Button>
                        )}
                      </TableCell>
                    </TableRow>
                  )
                })}
            </TableBody>
          </Table>
        </div>

        {/* Pagination */}
        {meta && meta.last_page > 1 && (
          <div className="mt-4 flex items-center justify-between text-sm text-muted-foreground">
            <span>
              Showing {meta.from ?? 0}–{meta.to ?? 0} of {meta.total} payments
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
      </section>

      {/* Record payment dialog */}
      {selectedPayment && (
        <RecordPaymentDialog
          open={recordDialogOpen}
          onOpenChange={setRecordDialogOpen}
          paymentId={selectedPayment.id}
          invoiceNumber={selectedPayment.invoice?.invoice_number}
          currency={selectedPayment.invoice?.currency ?? "USD"}
          onSuccess={handleRecordSuccess}
        />
      )}
    </motion.div>
  )
}
