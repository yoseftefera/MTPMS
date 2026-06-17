"use client"

/**
 * Purchase Order detail page.
 *
 * Features:
 * - Header: PO number, status badge, supplier name, total amount
 * - Info cards: delivery address, required delivery date, department, created by
 * - Line items table: description, qty, UoM, unit price, total price
 * - Status timeline: progression with timestamps and Framer Motion animation
 * - Action buttons (role-based):
 *     "Issue PO"       — Procurement_Officer, status = draft
 *     "Accept" / "Reject" — Supplier, status = issued
 *     "Amend"          — Procurement_Officer, status = draft | issued | accepted
 *     "Cancel"         — Procurement_Officer, status = draft | issued | accepted
 * - Reject and Cancel open a Dialog with mandatory reason textarea (min 10 chars)
 * - Amend opens the AmendPOModal
 * - Amber banner when pending_supplier_acknowledgment = true
 * - Loading skeleton + error state with retry
 *
 * Validates: Requirements 10.2, 10.9, 22.6
 */

import { use, useState } from "react"
import Link from "next/link"
import { useForm } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"
import { motion } from "framer-motion"
import {
  ArrowLeft,
  RefreshCw,
  Circle,
  ArrowUpCircle,
  CheckCircle2,
  XOctagon,
  Ban,
  Edit,
  SendHorizonal,
  AlertTriangle,
} from "lucide-react"
import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
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
import { POStatusBadge } from "@/components/purchase-orders/POStatusBadge"
import { AmendPOModal } from "@/components/purchase-orders/AmendPOModal"
import {
  usePurchaseOrder,
  useIssuePO,
  useAcceptPO,
  useRejectPO,
  useCancelPO,
} from "@/hooks/usePurchaseOrders"
import { useAuthStore } from "@/store/authStore"
import { formatCurrency } from "@/lib/utils"
import { reasonSchema, type ReasonFormData } from "@/lib/validations/purchaseOrders"
import type { POHistoryEntry } from "@/types/purchaseOrder"

// ─── Loading skeleton ─────────────────────────────────────────────────────────

function DetailSkeleton() {
  return (
    <div className="space-y-6">
      <Skeleton className="h-5 w-32" />
      <div className="flex items-start justify-between gap-4">
        <Skeleton className="h-8 w-64" />
        <Skeleton className="h-9 w-28" />
      </div>
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {Array.from({ length: 4 }).map((_, i) => (
          <Skeleton key={i} className="h-20 rounded-xl" />
        ))}
      </div>
      <Skeleton className="h-48 rounded-xl" />
      <Skeleton className="h-64 rounded-xl" />
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

// ─── Timeline icon ────────────────────────────────────────────────────────────

function TimelineIcon({ action }: { action: string }) {
  const cls = "size-5 shrink-0"
  switch (action) {
    case "created":
      return <Circle className={`${cls} text-muted-foreground`} aria-hidden="true" />
    case "issued":
      return <ArrowUpCircle className={`${cls} text-blue-500`} aria-hidden="true" />
    case "accepted":
      return <CheckCircle2 className={`${cls} text-green-500`} aria-hidden="true" />
    case "rejected":
      return <XOctagon className={`${cls} text-destructive`} aria-hidden="true" />
    case "cancelled":
      return <Ban className={`${cls} text-muted-foreground`} aria-hidden="true" />
    case "amended":
      return <Edit className={`${cls} text-amber-500`} aria-hidden="true" />
    default:
      return <Circle className={`${cls} text-muted-foreground`} aria-hidden="true" />
  }
}

// ─── Status timeline ──────────────────────────────────────────────────────────

const containerVariants = {
  hidden: {},
  visible: { transition: { staggerChildren: 0.07 } },
}

const itemVariants = {
  hidden: { opacity: 0, x: -16 },
  visible: {
    opacity: 1,
    x: 0,
    transition: { duration: 0.25, ease: "easeOut" as const },
  },
}

function formatStatus(s: string): string {
  return s.replace(/_/g, " ").replace(/\b\w/g, (c) => c.toUpperCase())
}

function StatusTimeline({ history }: { history: POHistoryEntry[] }) {
  if (!history || history.length === 0) {
    return (
      <p className="text-sm text-muted-foreground">No history entries yet.</p>
    )
  }

  const sorted = [...history].sort(
    (a, b) => new Date(a.created_at).getTime() - new Date(b.created_at).getTime(),
  )

  return (
    <motion.ol
      variants={containerVariants}
      initial="hidden"
      animate="visible"
      className="relative space-y-0"
      aria-label="Purchase order status timeline"
    >
      {sorted.map((entry, i) => {
        const isLast = i === sorted.length - 1
        const statusTransition =
          entry.from_status && entry.to_status
            ? `${formatStatus(entry.from_status)} → ${formatStatus(entry.to_status)}`
            : null

        return (
          <motion.li
            key={entry.id}
            variants={itemVariants}
            className="relative flex gap-4 pb-6"
          >
            {/* Vertical connector */}
            {!isLast && (
              <div
                className="absolute left-[10px] top-5 h-full w-px bg-border"
                aria-hidden="true"
              />
            )}

            {/* Icon */}
            <div className="relative z-10 mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center">
              <TimelineIcon action={entry.action} />
            </div>

            {/* Content */}
            <div className="flex-1 min-w-0">
              <div className="flex flex-wrap items-baseline gap-2">
                <span className="text-sm font-medium capitalize">
                  {entry.action.replace(/_/g, " ")}
                </span>
                {statusTransition && (
                  <span className="text-xs text-muted-foreground">
                    {statusTransition}
                  </span>
                )}
              </div>
              {entry.comment && (
                <p className="mt-0.5 text-sm text-muted-foreground">
                  {entry.comment}
                </p>
              )}
              <p className="mt-1 text-xs text-muted-foreground">
                {entry.performer?.name ?? entry.performed_by} ·{" "}
                {new Date(entry.created_at).toLocaleString()}
              </p>
            </div>
          </motion.li>
        )
      })}
    </motion.ol>
  )
}

// ─── Reason dialog (shared for reject + cancel) ───────────────────────────────

interface ReasonDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  title: string
  description: string
  submitLabel: string
  submitVariant?: "destructive" | "default"
  isPending: boolean
  onConfirm: (reason: string) => void
}

function ReasonDialog({
  open,
  onOpenChange,
  title,
  description,
  submitLabel,
  submitVariant = "destructive",
  isPending,
  onConfirm,
}: ReasonDialogProps) {
  const {
    register,
    handleSubmit,
    reset,
    formState: { errors },
  } = useForm<ReasonFormData>({
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    resolver: zodResolver(reasonSchema) as any,
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
          <DialogTitle>{title}</DialogTitle>
          <DialogDescription>{description}</DialogDescription>
        </DialogHeader>
        <form id="reason-form" onSubmit={onSubmit} noValidate className="space-y-4">
          <div className="space-y-1.5">
            <Label htmlFor="reason-textarea">
              Reason <span aria-hidden="true" className="text-destructive">*</span>
            </Label>
            <Textarea
              id="reason-textarea"
              placeholder="Provide a reason (at least 10 characters)…"
              rows={4}
              aria-invalid={!!errors.reason}
              aria-describedby={errors.reason ? "reason-error" : undefined}
              {...register("reason")}
            />
            {errors.reason && (
              <p id="reason-error" role="alert" className="text-xs text-destructive">
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
            form="reason-form"
            variant={submitVariant}
            disabled={isPending}
          >
            {isPending ? "Processing…" : submitLabel}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

// ─── Framer Motion ────────────────────────────────────────────────────────────

const fadeIn = {
  hidden: { opacity: 0, y: 8 },
  visible: { opacity: 1, y: 0, transition: { duration: 0.25, ease: "easeOut" as const } },
}

// ─── Role constants ───────────────────────────────────────────────────────────

const OFFICER_ROLES = ["Procurement_Officer", "Tenant_Admin"]
const SUPPLIER_ROLES = ["Supplier"]

// ─── Page ─────────────────────────────────────────────────────────────────────

export default function PurchaseOrderDetailPage({
  params,
}: {
  params: Promise<{ id: string }>
}) {
  const { id } = use(params)
  const role = useAuthStore((s) => s.role)

  const { data, isLoading, isError, refetch } = usePurchaseOrder(id)
  const issuePO = useIssuePO()
  const acceptPO = useAcceptPO()
  const rejectPO = useRejectPO()
  const cancelPO = useCancelPO()

  const [amendOpen, setAmendOpen] = useState(false)
  const [rejectOpen, setRejectOpen] = useState(false)
  const [cancelOpen, setCancelOpen] = useState(false)
  const [actionError, setActionError] = useState<string | null>(null)

  const po = data?.data

  // ── Role-based permissions ────────────────────────────────────────────────

  const isOfficer = role !== null && OFFICER_ROLES.includes(role)
  const isSupplier = role !== null && SUPPLIER_ROLES.includes(role)

  const canIssue =
    isOfficer && po?.status === "draft"

  const canAccept =
    isSupplier && po?.status === "issued"

  const canReject =
    isSupplier && po?.status === "issued"

  const canAmend =
    isOfficer &&
    (po?.status === "draft" || po?.status === "issued" || po?.status === "accepted")

  const canCancel =
    isOfficer &&
    (po?.status === "draft" || po?.status === "issued" || po?.status === "accepted")

  // ── Actions ───────────────────────────────────────────────────────────────

  async function handleIssue() {
    if (!po) return
    setActionError(null)
    try {
      await issuePO.mutateAsync(po.id)
    } catch {
      setActionError("Failed to issue purchase order. Please try again.")
    }
  }

  async function handleAccept() {
    if (!po) return
    setActionError(null)
    try {
      await acceptPO.mutateAsync(po.id)
    } catch {
      setActionError("Failed to accept purchase order. Please try again.")
    }
  }

  async function handleRejectConfirm(reason: string) {
    if (!po) return
    setActionError(null)
    try {
      await rejectPO.mutateAsync({ id: po.id, reason })
      setRejectOpen(false)
    } catch {
      setActionError("Failed to reject purchase order. Please try again.")
    }
  }

  async function handleCancelConfirm(reason: string) {
    if (!po) return
    setActionError(null)
    try {
      await cancelPO.mutateAsync({ id: po.id, reason })
      setCancelOpen(false)
    } catch {
      setActionError("Failed to cancel purchase order. Please try again.")
    }
  }

  // ── Loading / error ───────────────────────────────────────────────────────

  if (isLoading) return <DetailSkeleton />

  if (isError || !po) {
    return (
      <div className="flex flex-col items-center gap-4 py-16">
        <p className="text-sm text-muted-foreground">
          Failed to load purchase order.
        </p>
        <Button variant="outline" size="sm" onClick={() => refetch()}>
          <RefreshCw className="size-3.5" aria-hidden="true" />
          Retry
        </Button>
      </div>
    )
  }

  const lineItems = po.items ?? []
  const grandTotal = lineItems.reduce((sum, item) => {
    const tp = parseFloat(String(item.total_price ?? "0"))
    return sum + (isNaN(tp) ? 0 : tp)
  }, 0)

  const anyActionPending =
    issuePO.isPending ||
    acceptPO.isPending ||
    rejectPO.isPending ||
    cancelPO.isPending

  // ── Render ────────────────────────────────────────────────────────────────

  return (
    <motion.div
      className="space-y-6"
      initial="hidden"
      animate="visible"
      variants={fadeIn}
    >
      {/* Back link */}
      <Link
        href="/purchase-orders"
        className="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground transition-colors"
      >
        <ArrowLeft className="size-4" aria-hidden="true" />
        Back to Purchase Orders
      </Link>

      {/* Pending supplier acknowledgment banner */}
      {po.pending_supplier_acknowledgment && (
        <Alert className="border-amber-200 bg-amber-50 dark:bg-amber-950/30" role="status">
          <AlertTriangle
            className="size-4 text-amber-600 dark:text-amber-400"
            aria-hidden="true"
          />
          <AlertDescription className="text-amber-700 dark:text-amber-300">
            Pending supplier acknowledgment — this PO has been amended after
            acceptance and awaits the supplier&apos;s confirmation.
          </AlertDescription>
        </Alert>
      )}

      {/* Header */}
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div className="space-y-1">
          <div className="flex flex-wrap items-center gap-3">
            <h1 className="text-2xl font-semibold font-mono tracking-tight">
              {po.po_number}
            </h1>
            <POStatusBadge status={po.status} />
            {po.pending_supplier_acknowledgment && (
              <Badge
                variant="warning"
                aria-label="Pending supplier acknowledgment"
              >
                Pending Acknowledgment
              </Badge>
            )}
          </div>
          <p className="text-muted-foreground">
            {po.supplier?.organization_name ?? "—"}
          </p>
        </div>

        {/* Total */}
        <div className="text-right">
          <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
            Total Amount
          </p>
          <p className="text-xl font-semibold tabular-nums">
            {formatCurrency(po.total_amount, po.currency)}
          </p>
          <p className="text-xs text-muted-foreground">{po.currency}</p>
        </div>
      </div>

      {/* Action buttons */}
      <div className="flex flex-wrap items-center gap-2">
        {canIssue && (
          <Button
            onClick={handleIssue}
            disabled={anyActionPending}
            aria-label="Issue purchase order to supplier"
          >
            <SendHorizonal className="size-4" aria-hidden="true" />
            {issuePO.isPending ? "Issuing…" : "Issue PO"}
          </Button>
        )}
        {canAccept && (
          <Button
            variant="default"
            onClick={handleAccept}
            disabled={anyActionPending}
            aria-label="Accept purchase order"
            className="bg-green-600 hover:bg-green-700 text-white"
          >
            <CheckCircle2 className="size-4" aria-hidden="true" />
            {acceptPO.isPending ? "Accepting…" : "Accept"}
          </Button>
        )}
        {canReject && (
          <Button
            variant="destructive"
            onClick={() => setRejectOpen(true)}
            disabled={anyActionPending}
            aria-label="Reject purchase order"
          >
            <XOctagon className="size-4" aria-hidden="true" />
            Reject
          </Button>
        )}
        {canAmend && (
          <Button
            variant="outline"
            onClick={() => setAmendOpen(true)}
            disabled={anyActionPending}
            aria-label="Amend purchase order"
          >
            <Edit className="size-4" aria-hidden="true" />
            Amend
          </Button>
        )}
        {canCancel && (
          <Button
            variant="outline"
            onClick={() => setCancelOpen(true)}
            disabled={anyActionPending}
            aria-label="Cancel purchase order"
            className="text-destructive hover:text-destructive border-destructive/30 hover:bg-destructive/5"
          >
            <Ban className="size-4" aria-hidden="true" />
            Cancel
          </Button>
        )}
      </div>

      {actionError && (
        <Alert variant="destructive" role="alert">
          <AlertDescription>{actionError}</AlertDescription>
        </Alert>
      )}

      {/* Info cards */}
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <InfoCard
          label="Delivery Address"
          value={po.delivery_address || "—"}
        />
        <InfoCard
          label="Required Delivery Date"
          value={
            po.required_delivery_date
              ? new Date(po.required_delivery_date).toLocaleDateString()
              : "—"
          }
        />
        <InfoCard
          label="Department"
          value={po.department?.name ?? "—"}
          sub={po.department?.code}
        />
        <InfoCard
          label="Created By"
          value={po.creator?.name ?? "—"}
          sub={
            po.issued_at
              ? `Issued: ${new Date(po.issued_at).toLocaleDateString()}`
              : po.created_at
                ? `Created: ${new Date(po.created_at).toLocaleDateString()}`
                : undefined
          }
        />
      </div>

      {/* Notes */}
      {po.notes && (
        <Card className="p-4">
          <h2 className="mb-2 text-sm font-semibold">Notes</h2>
          <p className="text-sm text-muted-foreground whitespace-pre-wrap">
            {po.notes}
          </p>
        </Card>
      )}

      <Separator />

      {/* Line items table */}
      <section aria-labelledby="po-line-items-heading">
        <h2 id="po-line-items-heading" className="mb-3 text-base font-semibold">
          Line Items
        </h2>
        <div className="rounded-xl border border-border">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>#</TableHead>
                <TableHead>Description</TableHead>
                <TableHead className="text-right">Qty</TableHead>
                <TableHead>UoM</TableHead>
                <TableHead className="text-right">Unit Price</TableHead>
                <TableHead className="text-right">Total Price</TableHead>
                <TableHead className="text-right">Received</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {lineItems.length === 0 ? (
                <TableRow>
                  <TableCell
                    colSpan={7}
                    className="py-8 text-center text-muted-foreground text-sm"
                  >
                    No line items.
                  </TableCell>
                </TableRow>
              ) : (
                lineItems.map((item, idx) => {
                  const qty = parseFloat(String(item.quantity ?? "0"))
                  const price = parseFloat(String(item.unit_price ?? "0"))
                  const total = parseFloat(String(item.total_price ?? "0"))
                  const received = parseFloat(String(item.received_quantity ?? "0"))

                  return (
                    <TableRow key={item.id}>
                      <TableCell className="text-muted-foreground text-sm">
                        {idx + 1}
                      </TableCell>
                      <TableCell>{item.description}</TableCell>
                      <TableCell className="text-right tabular-nums">
                        {isNaN(qty) ? item.quantity : qty.toLocaleString()}
                      </TableCell>
                      <TableCell>{item.unit_of_measure}</TableCell>
                      <TableCell className="text-right tabular-nums">
                        {formatCurrency(
                          isNaN(price) ? "0" : price.toFixed(2),
                          po.currency,
                        )}
                      </TableCell>
                      <TableCell className="text-right tabular-nums font-medium">
                        {formatCurrency(
                          isNaN(total) ? "0" : total.toFixed(2),
                          po.currency,
                        )}
                      </TableCell>
                      <TableCell className="text-right tabular-nums text-muted-foreground">
                        {isNaN(received) ? "0" : received.toLocaleString()}
                      </TableCell>
                    </TableRow>
                  )
                })
              )}
            </TableBody>
          </Table>
        </div>

        {/* Grand total */}
        {lineItems.length > 0 && (
          <div className="flex justify-end px-4 py-3 text-sm font-semibold">
            Total:{" "}
            <span className="ml-2 tabular-nums text-base">
              {formatCurrency(grandTotal.toFixed(2), po.currency)}
            </span>
          </div>
        )}
      </section>

      <Separator />

      {/* Status timeline */}
      <section aria-labelledby="po-timeline-heading">
        <h2 id="po-timeline-heading" className="mb-4 text-base font-semibold">
          Status Timeline
        </h2>
        <StatusTimeline history={po.history ?? []} />
      </section>

      {/* Amend modal */}
      {po && (
        <AmendPOModal
          po={po}
          open={amendOpen}
          onOpenChange={setAmendOpen}
          onSuccess={() => refetch()}
        />
      )}

      {/* Reject dialog */}
      <ReasonDialog
        open={rejectOpen}
        onOpenChange={setRejectOpen}
        title="Reject Purchase Order"
        description="Please provide a reason for rejecting this purchase order. The Procurement Officer will be notified."
        submitLabel="Reject PO"
        submitVariant="destructive"
        isPending={rejectPO.isPending}
        onConfirm={handleRejectConfirm}
      />

      {/* Cancel dialog */}
      <ReasonDialog
        open={cancelOpen}
        onOpenChange={setCancelOpen}
        title="Cancel Purchase Order"
        description="Please provide a reason for cancelling this purchase order. This action will release any encumbered budget."
        submitLabel="Cancel PO"
        submitVariant="destructive"
        isPending={cancelPO.isPending}
        onConfirm={handleCancelConfirm}
      />
    </motion.div>
  )
}
