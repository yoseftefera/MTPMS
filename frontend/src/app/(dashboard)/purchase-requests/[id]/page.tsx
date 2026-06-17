"use client"

/**
 * Purchase Request detail page.
 *
 * Features:
 * - Header: PR number, title, status badge, action buttons (Submit / Cancel)
 * - Info cards: Department, Submitted By, Estimated Total, Required Date, Submitted At
 * - Line items table: Description, Qty, UoM, Unit Price, Line Total
 * - History timeline with Framer Motion staggered animation
 * - Documents section with download links
 * - Loading skeleton + error state
 *
 * Validates: Requirements 5.2, 5.5, 5.7, 5.8, 22.5, 22.6
 */

import { use, useState } from "react"
import { motion } from "framer-motion"
import {
  ArrowLeft,
  SendHorizonal,
  XCircle,
  RefreshCw,
  Circle,
  ArrowUpCircle,
  CheckCircle2,
  XOctagon,
  Ban,
  CornerDownLeft,
  FileText,
  Download,
} from "lucide-react"
import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { Skeleton } from "@/components/ui/skeleton"
import { Alert, AlertDescription } from "@/components/ui/alert"
import { Card } from "@/components/ui/card"
import {
  Table,
  TableHeader,
  TableBody,
  TableRow,
  TableHead,
  TableCell,
} from "@/components/ui/table"
import { Separator } from "@/components/ui/separator"
import { usePurchaseRequest, useSubmitPR, useCancelPR } from "@/hooks/usePurchaseRequest"
import { useAuthStore } from "@/store/authStore"
import { formatCurrency } from "@/lib/utils"
import type { PRStatus, PurchaseRequestHistory, PRDocument } from "@/types/purchaseRequest"

// ─── Status badge ─────────────────────────────────────────────────────────────

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
    <Badge variant={config.variant} aria-label={`Status: ${config.label}`}>
      {config.label}
    </Badge>
  )
}

// ─── History timeline icon per action ────────────────────────────────────────

function TimelineIcon({ action }: { action: string }) {
  const cls = "size-5 shrink-0"
  switch (action) {
    case "created":
      return <Circle className={`${cls} text-muted-foreground`} aria-hidden="true" />
    case "submitted":
      return <ArrowUpCircle className={`${cls} text-blue-500`} aria-hidden="true" />
    case "approved":
      return <CheckCircle2 className={`${cls} text-green-500`} aria-hidden="true" />
    case "rejected":
      return <XOctagon className={`${cls} text-destructive`} aria-hidden="true" />
    case "cancelled":
      return <Ban className={`${cls} text-muted-foreground`} aria-hidden="true" />
    case "returned":
    case "revision_required":
      return <CornerDownLeft className={`${cls} text-orange-500`} aria-hidden="true" />
    default:
      return <Circle className={`${cls} text-muted-foreground`} aria-hidden="true" />
  }
}

// ─── History timeline ─────────────────────────────────────────────────────────

const containerVariants = {
  hidden: {},
  visible: { transition: { staggerChildren: 0.07 } },
}

const itemVariants = {
  hidden: { opacity: 0, x: -16 },
  visible: { opacity: 1, x: 0, transition: { duration: 0.25, ease: "easeOut" as const } },
}

function HistoryTimeline({ history }: { history: PurchaseRequestHistory[] }) {
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
      aria-label="Purchase request history timeline"
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
            {/* Vertical connector line */}
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

function formatStatus(s: string): string {
  return s.replace(/_/g, " ").replace(/\b\w/g, (c) => c.toUpperCase())
}

// ─── Documents section ────────────────────────────────────────────────────────

function DocumentsList({ documents }: { documents?: PRDocument[] }) {
  if (!documents || documents.length === 0) {
    return <p className="text-sm text-muted-foreground">No documents attached.</p>
  }

  return (
    <ul className="space-y-2" aria-label="Attached documents">
      {documents.map((doc) => (
        <li
          key={doc.id}
          className="flex items-center justify-between rounded-md border border-border bg-muted/30 px-4 py-2.5"
        >
          <span className="flex items-center gap-2 text-sm">
            <FileText className="size-4 text-muted-foreground shrink-0" aria-hidden="true" />
            <span className="truncate">{doc.file_name}</span>
          </span>
          <a
            href={doc.file_path}
            target="_blank"
            rel="noopener noreferrer"
            className="ml-4 shrink-0 inline-flex items-center gap-1.5 rounded-sm text-xs text-primary hover:underline underline-offset-2"
            aria-label={`Download ${doc.file_name}`}
          >
            <Download className="size-3.5" aria-hidden="true" />
            Download
          </a>
        </li>
      ))}
    </ul>
  )
}

// ─── Loading skeleton ─────────────────────────────────────────────────────────

function DetailSkeleton() {
  return (
    <div className="space-y-6">
      <Skeleton className="h-8 w-64" />
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

// ─── Roles that can submit / cancel ──────────────────────────────────────────

const CAN_SUBMIT_ROLES = [
  "Department_Staff",
  "Department_Manager",
  "Finance_Officer",
  "Procurement_Officer",
  "Tenant_Admin",
]

const CAN_CANCEL_ROLES = [
  "Department_Staff",
  "Department_Manager",
  "Finance_Officer",
  "Procurement_Officer",
  "Tenant_Admin",
]

// ─── Page component ───────────────────────────────────────────────────────────

export default function PurchaseRequestDetailPage({
  params,
}: {
  params: Promise<{ id: string }>
}) {
  const { id } = use(params)
  const role = useAuthStore((s) => s.role)

  const { data, isLoading, isError, refetch } = usePurchaseRequest(id)
  const submitPR = useSubmitPR()
  const cancelPR = useCancelPR()

  const [actionError, setActionError] = useState<string | null>(null)

  const pr = data?.data

  const canSubmit =
    role !== null &&
    CAN_SUBMIT_ROLES.includes(role) &&
    (pr?.status === "draft" || pr?.status === "revision_required")

  const canCancel =
    role !== null &&
    CAN_CANCEL_ROLES.includes(role) &&
    (pr?.status === "draft" ||
      pr?.status === "pending_approval" ||
      pr?.status === "revision_required")

  async function handleSubmit() {
    setActionError(null)
    try {
      await submitPR.mutateAsync(id)
    } catch {
      setActionError("Failed to submit purchase request. Please try again.")
    }
  }

  async function handleCancel() {
    setActionError(null)
    try {
      await cancelPR.mutateAsync({ id })
    } catch {
      setActionError("Failed to cancel purchase request. Please try again.")
    }
  }

  // ── Loading / error states ───────────────────────────────────────────────────

  if (isLoading) return <DetailSkeleton />

  if (isError || !pr) {
    return (
      <div className="flex flex-col items-center gap-4 py-16">
        <p className="text-sm text-muted-foreground">
          Failed to load purchase request.
        </p>
        <Button variant="outline" size="sm" onClick={() => refetch()}>
          <RefreshCw className="size-3.5" aria-hidden="true" />
          Retry
        </Button>
      </div>
    )
  }

  const lineItems = pr.items ?? []
  const grandTotal = lineItems.reduce((sum, item) => {
    const qty = parseFloat(String(item.quantity))
    const price = parseFloat(String(item.estimated_unit_price))
    return sum + (isNaN(qty) || isNaN(price) ? 0 : qty * price)
  }, 0)

  // ── Render ───────────────────────────────────────────────────────────────────

  return (
    <div className="space-y-6">
      {/* Back link */}
      <div>
        <a
          href="/purchase-requests"
          className="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground transition-colors"
        >
          <ArrowLeft className="size-4" aria-hidden="true" />
          Back to Purchase Requests
        </a>
      </div>

      {/* Header */}
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div className="space-y-1">
          <div className="flex items-center gap-3 flex-wrap">
            <h1 className="text-2xl font-semibold tracking-tight">{pr.pr_number}</h1>
            <StatusBadge status={pr.status} />
          </div>
          <p className="text-muted-foreground">{pr.title}</p>
        </div>

        {/* Action buttons */}
        <div className="flex items-center gap-2 flex-wrap">
          {canSubmit && (
            <Button
              onClick={handleSubmit}
              disabled={submitPR.isPending}
              aria-label="Submit purchase request for approval"
            >
              <SendHorizonal className="size-4" aria-hidden="true" />
              {submitPR.isPending ? "Submitting…" : "Submit for Approval"}
            </Button>
          )}
          {canCancel && (
            <Button
              variant="outline"
              onClick={handleCancel}
              disabled={cancelPR.isPending}
              aria-label="Cancel purchase request"
              className="text-destructive hover:text-destructive border-destructive/30 hover:bg-destructive/5"
            >
              <XCircle className="size-4" aria-hidden="true" />
              {cancelPR.isPending ? "Cancelling…" : "Cancel"}
            </Button>
          )}
        </div>
      </div>

      {actionError && (
        <Alert variant="destructive" role="alert">
          <AlertDescription>{actionError}</AlertDescription>
        </Alert>
      )}

      {/* Info cards */}
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <InfoCard label="Department" value={pr.department?.name ?? "—"} sub={pr.department?.code} />
        <InfoCard label="Submitted By" value={pr.submitter?.name ?? "—"} sub={pr.submitter?.email} />
        <InfoCard
          label="Estimated Total"
          value={formatCurrency(pr.estimated_total, pr.currency)}
          sub={pr.currency}
        />
        <InfoCard
          label={pr.submitted_at ? "Submitted At" : "Required Date"}
          value={
            pr.submitted_at
              ? new Date(pr.submitted_at).toLocaleDateString()
              : pr.required_date
                ? new Date(pr.required_date).toLocaleDateString()
                : "—"
          }
        />
      </div>

      {/* Description */}
      {pr.description && (
        <Card className="p-4">
          <h2 className="mb-2 text-sm font-semibold">Description</h2>
          <p className="text-sm text-muted-foreground whitespace-pre-wrap">{pr.description}</p>
        </Card>
      )}

      <Separator />

      {/* Line items table */}
      <section aria-labelledby="line-items-heading">
        <h2
          id="line-items-heading"
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
                <TableHead>UoM</TableHead>
                <TableHead className="text-right">Unit Price</TableHead>
                <TableHead className="text-right">Line Total</TableHead>
                <TableHead>Budget Code</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {lineItems.length === 0 ? (
                <TableRow>
                  <TableCell colSpan={7} className="py-8 text-center text-muted-foreground text-sm">
                    No line items.
                  </TableCell>
                </TableRow>
              ) : (
                lineItems.map((item, idx) => {
                  const qty = parseFloat(String(item.quantity))
                  const price = parseFloat(String(item.estimated_unit_price))
                  const lineTotal = isNaN(qty) || isNaN(price) ? 0 : qty * price

                  return (
                    <TableRow key={item.id}>
                      <TableCell className="text-muted-foreground">{idx + 1}</TableCell>
                      <TableCell>{item.description}</TableCell>
                      <TableCell className="text-right tabular-nums">{item.quantity}</TableCell>
                      <TableCell>{item.unit_of_measure}</TableCell>
                      <TableCell className="text-right tabular-nums">
                        {formatCurrency(String(item.estimated_unit_price), pr.currency)}
                      </TableCell>
                      <TableCell className="text-right tabular-nums font-medium">
                        {formatCurrency(lineTotal.toFixed(2), pr.currency)}
                      </TableCell>
                      <TableCell className="text-sm text-muted-foreground">
                        {item.budget_code ?? "—"}
                      </TableCell>
                    </TableRow>
                  )
                })
              )}
            </TableBody>
          </Table>
        </div>

        {/* Grand total row */}
        {lineItems.length > 0 && (
          <div className="flex justify-end px-4 py-3 text-sm font-semibold">
            Total:{" "}
            <span className="ml-2 tabular-nums text-base">
              {formatCurrency(grandTotal.toFixed(2), pr.currency)}
            </span>
          </div>
        )}
      </section>

      <Separator />

      {/* History timeline */}
      <section aria-labelledby="history-heading">
        <h2 id="history-heading" className="mb-4 text-base font-semibold">
          History
        </h2>
        <HistoryTimeline history={pr.history ?? []} />
      </section>

      <Separator />

      {/* Documents */}
      <section aria-labelledby="documents-heading">
        <h2 id="documents-heading" className="mb-4 text-base font-semibold">
          Documents
        </h2>
        <DocumentsList documents={pr.documents} />
      </section>
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
      <p className="mt-1 text-base font-semibold">{value}</p>
      {sub && <p className="text-xs text-muted-foreground">{sub}</p>}
    </Card>
  )
}
