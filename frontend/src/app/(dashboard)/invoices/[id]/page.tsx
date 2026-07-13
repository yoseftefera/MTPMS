"use client"

/**
 * Invoice detail page.
 *
 * Features:
 * - Header: invoice number, status badge, supplier name, total/paid amounts
 * - PO or contract reference card
 * - Line items table (when items exist)
 * - Approval history timeline
 * - Approve/Reject actions (Finance_Officer, status = pending_approval)
 * - Reject opens a Dialog with mandatory reason (min 10 chars)
 * - Loading skeleton + error state with retry
 *
 * Validates: Requirements 14.1, 14.4, 14.9, 14.10, 22.6
 */

import { use, useState } from "react"
import Link from "next/link"
import { useForm } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"
import { motion } from "framer-motion"
import {
  ArrowLeft,
  RefreshCw,
  CheckCircle2,
  XOctagon,
  Circle,
} from "lucide-react"
import { Button } from "@/components/ui/button"
import { Card } from "@/components/ui/card"
import { Skeleton } from "@/components/ui/skeleton"
import { Alert, AlertDescription } from "@/components/ui/alert"
import { Separator } from "@/components/ui/separator"
import {
  Table,
  TableHeader,
  TableBody,
  TableRow,
  TableHead,
  TableCell,
} from "@/components/ui/table"
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from "@/components/ui/dialog"
import { Label } from "@/components/ui/label"
import { Textarea } from "@/components/ui/textarea"
import { InvoiceStatusBadge } from "@/components/invoices/InvoiceStatusBadge"
import { useInvoice, useApproveInvoice, useRejectInvoice } from "@/hooks/useInvoices"
import { useAuthStore } from "@/store/authStore"
import { formatCurrency } from "@/lib/utils"
import {
  rejectInvoiceSchema,
  type RejectInvoiceFormData,
} from "@/lib/validations/invoices"
import type { InvoiceApprovalEntry } from "@/types/invoice"

// ─── Loading skeleton ─────────────────────────────────────────────────────────

function DetailSkeleton() {
  return (
    <div className="space-y-6">
      <Skeleton className="h-5 w-32" />
      <div className="flex items-start justify-between gap-4">
        <Skeleton className="h-8 w-64" />
        <Skeleton className="h-9 w-28" />
      </div>
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {Array.from({ length: 3 }).map((_, i) => (
          <Skeleton key={i} className="h-20 rounded-xl" />
        ))}
      </div>
      <Skeleton className="h-48 rounded-xl" />
      <Skeleton className="h-48 rounded-xl" />
    </div>
  )
}

// ─── InfoCard helper ──────────────────────────────────────────────────────────

function InfoCard({
  label,
  value,
  sub,
}: {
  label: string
  value: string
  sub?: string
}) {
  return (
    <Card className="p-4">
      <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
        {label}
      </p>
      <p className="mt-1 text-sm font-semibold leading-snug">{value}</p>
      {sub && <p className="text-xs text-muted-foreground">{sub}</p>}
    </Card>
  )
}

// ─── Approval history ─────────────────────────────────────────────────────────

const ACTION_ICONS: Record<string, React.ReactNode> = {
  approved: <CheckCircle2 className="size-5 text-green-500" aria-hidden="true" />,
  rejected: <XOctagon className="size-5 text-destructive" aria-hidden="true" />,
}

function ApprovalHistory({ approvals }: { approvals: InvoiceApprovalEntry[] }) {
  if (!approvals || approvals.length === 0) {
    return (
      <p className="text-sm text-muted-foreground">No approval history yet.</p>
    )
  }

  const sorted = [...approvals].sort(
    (a, b) => new Date(a.created_at).getTime() - new Date(b.created_at).getTime(),
  )

  return (
    <ol className="relative space-y-0" aria-label="Invoice approval history">
      {sorted.map((entry, i) => {
        const isLast = i === sorted.length - 1
        const icon = ACTION_ICONS[entry.action] ?? (
          <Circle className="size-5 text-muted-foreground" aria-hidden="true" />
        )

        return (
          <li key={entry.id} className="relative flex gap-4 pb-6">
            {!isLast && (
              <div
                className="absolute left-[10px] top-5 h-full w-px bg-border"
                aria-hidden="true"
              />
            )}
            <div className="relative z-10 mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center">
              {icon}
            </div>
            <div className="flex-1 min-w-0">
              <div className="flex flex-wrap items-baseline gap-2">
                <span className="text-sm font-medium capitalize">
                  {entry.action}
                </span>
              </div>
              {entry.comment && (
                <p className="mt-0.5 text-sm text-muted-foreground">
                  {entry.comment}
                </p>
              )}
              <p className="mt-1 text-xs text-muted-foreground">
                {entry.approver?.name ?? "—"} ·{" "}
                {entry.acted_at
                  ? new Date(entry.acted_at).toLocaleString()
                  : new Date(entry.created_at).toLocaleString()}
              </p>
            </div>
          </li>
        )
      })}
    </ol>
  )
}

// ─── Reject dialog ────────────────────────────────────────────────────────────

interface RejectDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  isPending: boolean
  onConfirm: (reason: string) => void
}

function RejectDialog({
  open,
  onOpenChange,
  isPending,
  onConfirm,
}: RejectDialogProps) {
  const {
    register,
    handleSubmit,
    reset,
    formState: { errors },
  } = useForm<RejectInvoiceFormData>({
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    resolver: zodResolver(rejectInvoiceSchema) as any,
    defaultValues: { reason: "" },
  })

  function handleClose() {
    reset()
    onOpenChange(false)
  }

  const onSubmit = handleSubmit(async (data) => {
    await onConfirm(data.reason)
    reset()
  })

  return (
    <Dialog open={open} onOpenChange={handleClose}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Reject Invoice</DialogTitle>
          <DialogDescription>
            Please provide a reason for rejecting this invoice. The supplier will
            be notified.
          </DialogDescription>
        </DialogHeader>
        <form id="reject-invoice-form" onSubmit={onSubmit} noValidate className="space-y-4">
          <div className="space-y-1.5">
            <Label htmlFor="reject-reason">
              Reason{" "}
              <span aria-hidden="true" className="text-destructive">*</span>
            </Label>
            <Textarea
              id="reject-reason"
              placeholder="Provide a reason (at least 10 characters)…"
              rows={4}
              aria-invalid={!!errors.reason}
              aria-describedby={errors.reason ? "reject-reason-error" : undefined}
              {...register("reason")}
            />
            {errors.reason && (
              <p id="reject-reason-error" role="alert" className="text-xs text-destructive">
                {errors.reason.message}
              </p>
            )}
          </div>
        </form>
        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            onClick={handleClose}
            disabled={isPending}
          >
            Cancel
          </Button>
          <Button
            type="submit"
            form="reject-invoice-form"
            variant="destructive"
            disabled={isPending}
          >
            {isPending ? "Rejecting…" : "Reject Invoice"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
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

export default function InvoiceDetailPage({
  params,
}: {
  params: Promise<{ id: string }>
}) {
  const { id } = use(params)
  const role = useAuthStore((s) => s.role)

  const { data, isLoading, isError, refetch } = useInvoice(id)
  const approveInvoice = useApproveInvoice()
  const rejectInvoice = useRejectInvoice()

  const [rejectOpen, setRejectOpen] = useState(false)
  const [actionError, setActionError] = useState<string | null>(null)

  const invoice = data?.data

  // ── Role-based permissions ─────────────────────────────────────────────────

  const isFinanceOfficer =
    role === "Finance_Officer" || role === "Tenant_Admin"

  const canApproveOrReject =
    isFinanceOfficer && invoice?.status === "pending_approval"

  // ── Actions ────────────────────────────────────────────────────────────────

  async function handleApprove() {
    if (!invoice) return
    setActionError(null)
    try {
      await approveInvoice.mutateAsync(invoice.id)
    } catch {
      setActionError("Failed to approve invoice. Please try again.")
    }
  }

  async function handleRejectConfirm(reason: string) {
    if (!invoice) return
    setActionError(null)
    try {
      await rejectInvoice.mutateAsync({ id: invoice.id, reason })
      setRejectOpen(false)
    } catch {
      setActionError("Failed to reject invoice. Please try again.")
    }
  }

  // ── Loading / error ────────────────────────────────────────────────────────

  if (isLoading) return <DetailSkeleton />

  if (isError || !invoice) {
    return (
      <div className="flex flex-col items-center gap-4 py-16">
        <p className="text-sm text-muted-foreground">
          Failed to load invoice.
        </p>
        <Button variant="outline" size="sm" onClick={() => refetch()}>
          <RefreshCw className="size-3.5" aria-hidden="true" />
          Retry
        </Button>
      </div>
    )
  }

  const lineItems = invoice.items ?? []
  const anyPending = approveInvoice.isPending || rejectInvoice.isPending

  // ── Render ─────────────────────────────────────────────────────────────────

  return (
    <motion.div
      className="space-y-6"
      initial="hidden"
      animate="visible"
      variants={fadeIn}
    >
      {/* Back link */}
      <Link
        href="/invoices"
        className="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground transition-colors"
      >
        <ArrowLeft className="size-4" aria-hidden="true" />
        Back to Invoices
      </Link>

      {/* Header */}
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div className="space-y-1">
          <div className="flex flex-wrap items-center gap-3">
            <h1 className="text-2xl font-semibold font-mono tracking-tight">
              {invoice.invoice_number}
            </h1>
            <InvoiceStatusBadge status={invoice.status} />
          </div>
          <p className="text-muted-foreground">
            {invoice.supplier?.organization_name ?? "—"}
          </p>
        </div>

        {/* Amounts */}
        <div className="flex gap-6">
          <div className="text-right">
            <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
              Total Amount
            </p>
            <p className="text-xl font-semibold tabular-nums">
              {formatCurrency(invoice.total_amount, invoice.currency)}
            </p>
          </div>
          <div className="text-right">
            <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
              Paid
            </p>
            <p className="text-xl font-semibold tabular-nums text-green-600 dark:text-green-400">
              {formatCurrency(invoice.paid_amount, invoice.currency)}
            </p>
          </div>
        </div>
      </div>

      {/* Action buttons */}
      {canApproveOrReject && (
        <div className="flex flex-wrap items-center gap-2">
          <Button
            onClick={handleApprove}
            disabled={anyPending}
            className="bg-green-600 hover:bg-green-700 text-white"
            aria-label="Approve invoice"
          >
            <CheckCircle2 className="size-4" aria-hidden="true" />
            {approveInvoice.isPending ? "Approving…" : "Approve"}
          </Button>
          <Button
            variant="destructive"
            onClick={() => setRejectOpen(true)}
            disabled={anyPending}
            aria-label="Reject invoice"
          >
            <XOctagon className="size-4" aria-hidden="true" />
            Reject
          </Button>
        </div>
      )}

      {actionError && (
        <Alert variant="destructive" role="alert">
          <AlertDescription>{actionError}</AlertDescription>
        </Alert>
      )}

      {/* Rejection reason banner */}
      {invoice.rejection_reason && (
        <Alert variant="destructive" role="status">
          <AlertDescription>
            <span className="font-medium">Rejection reason:</span>{" "}
            {invoice.rejection_reason}
          </AlertDescription>
        </Alert>
      )}

      {/* Info cards */}
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <InfoCard
          label="Invoice Date"
          value={
            invoice.invoice_date
              ? new Date(invoice.invoice_date).toLocaleDateString()
              : "—"
          }
        />
        <InfoCard
          label="Due Date"
          value={
            invoice.due_date
              ? new Date(invoice.due_date).toLocaleDateString()
              : "—"
          }
        />
        <InfoCard label="Currency" value={invoice.currency} />
      </div>

      {/* PO / Contract reference card */}
      {(invoice.purchase_order || invoice.contract) && (
        <Card className="p-4">
          <h2 className="mb-3 text-sm font-semibold">
            {invoice.purchase_order ? "Purchase Order Reference" : "Contract Reference"}
          </h2>
          {invoice.purchase_order ? (
            <div className="flex items-center gap-3">
              <span className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                PO Number
              </span>
              <Link
                href={`/purchase-orders/${invoice.purchase_order.id}`}
                className="font-mono text-sm font-medium text-primary hover:underline"
              >
                {invoice.purchase_order.po_number}
              </Link>
            </div>
          ) : invoice.contract ? (
            <div className="space-y-1">
              <div className="flex items-center gap-3">
                <span className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                  Contract #
                </span>
                <Link
                  href={`/contracts/${invoice.contract.id}`}
                  className="font-mono text-sm font-medium text-primary hover:underline"
                >
                  {invoice.contract.contract_number}
                </Link>
              </div>
              {invoice.contract.title && (
                <p className="text-sm text-muted-foreground pl-1">
                  {invoice.contract.title}
                </p>
              )}
            </div>
          ) : null}
        </Card>
      )}

      {/* Notes */}
      {invoice.notes && (
        <Card className="p-4">
          <h2 className="mb-2 text-sm font-semibold">Notes</h2>
          <p className="text-sm text-muted-foreground whitespace-pre-wrap">
            {invoice.notes}
          </p>
        </Card>
      )}

      {/* Line items table */}
      {lineItems.length > 0 && (
        <>
          <Separator />
          <section aria-labelledby="invoice-line-items-heading">
            <h2
              id="invoice-line-items-heading"
              className="mb-3 text-base font-semibold"
            >
              Line Items
            </h2>
            <div className="rounded-xl border border-border">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>#</TableHead>
                    <TableHead>Description</TableHead>
                    <TableHead className="text-right">Qty</TableHead>
                    <TableHead className="text-right">Unit Price</TableHead>
                    <TableHead className="text-right">Total</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {lineItems.map((item, idx) => {
                    const qty = parseFloat(String(item.quantity ?? "0"))
                    const unitPrice = parseFloat(String(item.unit_price ?? "0"))
                    const total = parseFloat(String(item.total_price ?? "0"))

                    return (
                      <TableRow key={item.id}>
                        <TableCell className="text-muted-foreground text-sm">
                          {idx + 1}
                        </TableCell>
                        <TableCell>{item.description}</TableCell>
                        <TableCell className="text-right tabular-nums">
                          {isNaN(qty) ? item.quantity : qty.toLocaleString()}
                        </TableCell>
                        <TableCell className="text-right tabular-nums">
                          {formatCurrency(
                            isNaN(unitPrice) ? "0" : unitPrice.toFixed(2),
                            invoice.currency,
                          )}
                        </TableCell>
                        <TableCell className="text-right tabular-nums font-medium">
                          {formatCurrency(
                            isNaN(total) ? "0" : total.toFixed(2),
                            invoice.currency,
                          )}
                        </TableCell>
                      </TableRow>
                    )
                  })}
                </TableBody>
              </Table>
            </div>
          </section>
        </>
      )}

      <Separator />

      {/* Approval history */}
      <section aria-labelledby="invoice-approval-heading">
        <h2 id="invoice-approval-heading" className="mb-4 text-base font-semibold">
          Approval History
        </h2>
        <ApprovalHistory approvals={invoice.approvals ?? []} />
      </section>

      {/* Reject dialog */}
      <RejectDialog
        open={rejectOpen}
        onOpenChange={setRejectOpen}
        isPending={rejectInvoice.isPending}
        onConfirm={handleRejectConfirm}
      />
    </motion.div>
  )
}
