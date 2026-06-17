"use client"

/**
 * Tender detail page (Procurement Officer / Tenant Admin view).
 *
 * Displays:
 * - Tender header: reference number, title, status badge, type badge
 * - Info cards: category, tender type, estimated value, deadline, published_at
 * - Description section
 * - Action buttons: Publish (draft), Cancel (published), Extend Deadline (published)
 * - Specification documents list with download links
 * - Bid list table (supplier name, submission date, bid amount hidden until evaluation,
 *   delivery days, status)
 * - Loading skeleton + error state
 *
 * Validates: Requirements 8.1, 8.3, 8.5, 8.7, 22.6
 */

import { use, useState } from "react"
import { motion } from "framer-motion"
import {
  ArrowLeft,
  RefreshCw,
  FileText,
  Download,
  CalendarClock,
  XCircle,
  Globe,
} from "lucide-react"
import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { Skeleton } from "@/components/ui/skeleton"
import { Alert, AlertDescription } from "@/components/ui/alert"
import { Card } from "@/components/ui/card"
import { Separator } from "@/components/ui/separator"
import {
  Table,
  TableHeader,
  TableBody,
  TableRow,
  TableHead,
  TableCell,
} from "@/components/ui/table"
import { TenderStatusBadge } from "@/components/tenders/TenderStatusBadge"
import { BidStatusBadge } from "@/components/tenders/BidStatusBadge"
import { TenderForm } from "@/components/tenders/TenderForm"
import { CancelTenderDialog } from "@/components/tenders/CancelTenderDialog"
import { ExtendDeadlineDialog } from "@/components/tenders/ExtendDeadlineDialog"
import { useTender, usePublishTender } from "@/hooks/useTenders"
import { useAuthStore } from "@/store/authStore"
import { formatCurrency } from "@/lib/utils"
import type { BidSummary, TenderDocument } from "@/types/tender"

// ─── Role guards ──────────────────────────────────────────────────────────────

const OFFICER_ROLES = ["Procurement_Officer", "Tenant_Admin"]

// ─── Type badge ───────────────────────────────────────────────────────────────

function TenderTypeBadge({ type }: { type: string }) {
  const label = type.replace(/_/g, " ")
  return (
    <Badge variant="outline" className="capitalize text-xs">
      {label}
    </Badge>
  )
}

// ─── Helpers ──────────────────────────────────────────────────────────────────

function formatDateTime(iso: string): string {
  return new Intl.DateTimeFormat("en-US", {
    dateStyle: "medium",
    timeStyle: "short",
  }).format(new Date(iso))
}

function isPast(iso: string): boolean {
  return new Date(iso) < new Date()
}

// ─── Info Card ────────────────────────────────────────────────────────────────

function InfoCard({
  label,
  value,
  sub,
  highlight,
}: {
  label: string
  value: React.ReactNode
  sub?: string
  highlight?: boolean
}) {
  return (
    <Card className="p-4">
      <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
        {label}
      </p>
      <p
        className={`mt-1 text-base font-semibold ${
          highlight ? "text-destructive" : ""
        }`}
      >
        {value}
      </p>
      {sub && <p className="text-xs text-muted-foreground">{sub}</p>}
    </Card>
  )
}

// ─── Documents section ────────────────────────────────────────────────────────

function DocumentsList({ documents }: { documents?: TenderDocument[] }) {
  if (!documents || documents.length === 0) {
    return (
      <p className="text-sm text-muted-foreground">
        No specification documents attached.
      </p>
    )
  }

  return (
    <ul className="space-y-2" aria-label="Tender documents">
      {documents.map((doc) => (
        <li
          key={doc.id}
          className="flex items-center justify-between rounded-md border border-border bg-muted/30 px-4 py-2.5"
        >
          <span className="flex items-center gap-2 text-sm">
            <FileText
              className="size-4 text-muted-foreground shrink-0"
              aria-hidden="true"
            />
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

// ─── Bid list table ───────────────────────────────────────────────────────────

/**
 * Bid amounts are hidden (shown as "—") while the tender is published
 * to prevent suppliers seeing competitor pricing (Requirement 8.7).
 * Once closed/awarded, officer can see amounts.
 */
function BidList({
  bids,
  tenderStatus,
}: {
  bids: BidSummary[]
  tenderStatus: string
}) {
  const showAmounts = tenderStatus === "closed" || tenderStatus === "awarded"

  if (bids.length === 0) {
    return (
      <p className="text-sm text-muted-foreground">No bids submitted yet.</p>
    )
  }

  return (
    <div className="rounded-xl border border-border">
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>Supplier</TableHead>
            <TableHead>Submitted At</TableHead>
            <TableHead className="text-right">Amount</TableHead>
            <TableHead className="text-right">Delivery Days</TableHead>
            <TableHead>Status</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {bids.map((bid) => (
            <TableRow key={bid.id}>
              {/* Supplier */}
              <TableCell>
                <span className="text-sm font-medium">
                  {bid.supplier?.organization_name ?? bid.supplier_name ?? "—"}
                </span>
                {bid.supplier?.contact_email && (
                  <span className="block text-xs text-muted-foreground">
                    {bid.supplier.contact_email}
                  </span>
                )}
              </TableCell>

              {/* Submitted at */}
              <TableCell className="text-sm text-muted-foreground">
                {bid.submitted_at ? formatDateTime(bid.submitted_at) : "—"}
              </TableCell>

              {/* Bid amount — hidden until evaluation (Req 8.7) */}
              <TableCell className="text-right tabular-nums text-sm">
                {showAmounts
                  ? formatCurrency(bid.total_amount, bid.currency)
                  : <span className="text-muted-foreground">Hidden</span>}
              </TableCell>

              {/* Delivery days */}
              <TableCell className="text-right tabular-nums text-sm">
                {bid.delivery_days} days
              </TableCell>

              {/* Status */}
              <TableCell>
                <BidStatusBadge status={bid.status} />
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </div>
  )
}

// ─── Loading skeleton ─────────────────────────────────────────────────────────

function DetailSkeleton() {
  return (
    <div className="space-y-6">
      <Skeleton className="h-8 w-72" />
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {Array.from({ length: 4 }).map((_, i) => (
          <Skeleton key={i} className="h-20 rounded-xl" />
        ))}
      </div>
      <Skeleton className="h-32 rounded-xl" />
      <Skeleton className="h-48 rounded-xl" />
    </div>
  )
}

// ─── Framer Motion variants ───────────────────────────────────────────────────

const fadeIn = {
  hidden: { opacity: 0, y: 8 },
  visible: { opacity: 1, y: 0, transition: { duration: 0.25, ease: "easeOut" as const } },
}

// ─── Page ─────────────────────────────────────────────────────────────────────

export default function TenderDetailPage({
  params,
}: {
  params: Promise<{ id: string }>
}) {
  const { id } = use(params)
  const role = useAuthStore((s) => s.role)

  const { data, isLoading, isError, refetch } = useTender(id)
  const publishTender = usePublishTender()

  const [editOpen, setEditOpen] = useState(false)
  const [cancelOpen, setCancelOpen] = useState(false)
  const [extendOpen, setExtendOpen] = useState(false)
  const [actionError, setActionError] = useState<string | null>(null)

  const tender = data?.data
  const isOfficer = role !== null && OFFICER_ROLES.includes(role)

  async function handlePublish() {
    setActionError(null)
    try {
      await publishTender.mutateAsync(id)
    } catch {
      setActionError("Failed to publish tender. Please try again.")
    }
  }

  // ── Loading / error ──────────────────────────────────────────────────────────

  if (isLoading) return <DetailSkeleton />

  if (isError || !tender) {
    return (
      <div className="flex flex-col items-center gap-4 py-16">
        <p className="text-sm text-muted-foreground">Failed to load tender.</p>
        <Button variant="outline" size="sm" onClick={() => refetch()}>
          <RefreshCw className="size-3.5" aria-hidden="true" />
          Retry
        </Button>
      </div>
    )
  }

  const bids = tender.bids ?? []
  const deadlinePast = isPast(tender.submission_deadline)

  // ── Render ───────────────────────────────────────────────────────────────────

  return (
    <motion.div
      className="space-y-6"
      initial="hidden"
      animate="visible"
      variants={fadeIn}
    >
      {/* Back */}
      <div>
        <a
          href="/tenders"
          className="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground transition-colors"
        >
          <ArrowLeft className="size-4" aria-hidden="true" />
          Back to Tenders
        </a>
      </div>

      {/* Header */}
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div className="space-y-1">
          <div className="flex items-center gap-3 flex-wrap">
            <h1 className="text-2xl font-semibold tracking-tight font-mono">
              {tender.reference_number}
            </h1>
            <TenderStatusBadge status={tender.status} />
            <TenderTypeBadge type={tender.tender_type} />
          </div>
          <p className="text-muted-foreground text-lg">{tender.title}</p>
        </div>

        {/* Action buttons — officer only */}
        {isOfficer && (
          <div className="flex flex-wrap items-center gap-2">
            {tender.status === "draft" && (
              <>
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => setEditOpen(true)}
                  aria-label="Edit tender"
                >
                  Edit
                </Button>
                <Button
                  size="sm"
                  disabled={publishTender.isPending}
                  onClick={handlePublish}
                  aria-label="Publish tender"
                >
                  <Globe className="size-4" aria-hidden="true" />
                  {publishTender.isPending ? "Publishing…" : "Publish"}
                </Button>
              </>
            )}

            {tender.status === "published" && !deadlinePast && (
              <>
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => setExtendOpen(true)}
                  aria-label="Extend submission deadline"
                >
                  <CalendarClock className="size-4" aria-hidden="true" />
                  Extend Deadline
                </Button>
                <Button
                  variant="outline"
                  size="sm"
                  className="text-destructive border-destructive/30 hover:bg-destructive/5"
                  onClick={() => setCancelOpen(true)}
                  aria-label="Cancel tender"
                >
                  <XCircle className="size-4" aria-hidden="true" />
                  Cancel
                </Button>
              </>
            )}
          </div>
        )}
      </div>

      {actionError && (
        <Alert variant="destructive" role="alert">
          <AlertDescription>{actionError}</AlertDescription>
        </Alert>
      )}

      {/* Info cards */}
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <InfoCard label="Category" value={tender.category} />
        <InfoCard
          label="Estimated Value"
          value={formatCurrency(tender.estimated_value, tender.currency ?? "USD")}
          sub={tender.currency ?? "USD"}
        />
        <InfoCard
          label="Submission Deadline"
          value={formatDateTime(tender.submission_deadline)}
          highlight={deadlinePast}
          sub={deadlinePast ? "Deadline passed" : undefined}
        />
        <InfoCard
          label="Published At"
          value={
            tender.published_at ? formatDateTime(tender.published_at) : "Not yet published"
          }
        />
      </div>

      {/* Description */}
      <Card className="p-4">
        <h2 className="mb-2 text-sm font-semibold">Description</h2>
        <p className="text-sm text-muted-foreground whitespace-pre-wrap">
          {tender.description}
        </p>
      </Card>

      {/* Cancellation reason */}
      {tender.cancellation_reason && (
        <Alert variant="destructive" role="note">
          <AlertDescription>
            <strong>Cancellation reason:</strong> {tender.cancellation_reason}
          </AlertDescription>
        </Alert>
      )}

      <Separator />

      {/* Specification documents */}
      <section aria-labelledby="tender-docs-heading">
        <h2 id="tender-docs-heading" className="mb-4 text-base font-semibold">
          Specification Documents{" "}
          <span className="text-sm font-normal text-muted-foreground">
            ({tender.documents?.length ?? 0})
          </span>
        </h2>
        <DocumentsList documents={tender.documents} />
      </section>

      <Separator />

      {/* Bid list */}
      <section aria-labelledby="bids-heading">
        <div className="mb-4 flex items-center justify-between gap-2">
          <h2 id="bids-heading" className="text-base font-semibold">
            Bids{" "}
            <span className="text-sm font-normal text-muted-foreground">
              ({bids.length})
            </span>
          </h2>
          {tender.status === "published" && (
            <p className="text-xs text-muted-foreground">
              Bid amounts are hidden until the tender closes.
            </p>
          )}
        </div>
        <BidList bids={bids} tenderStatus={tender.status} />
      </section>

      {/* Dialogs */}
      <TenderForm
        open={editOpen}
        onOpenChange={setEditOpen}
        tender={tender}
        onSuccess={() => setEditOpen(false)}
      />

      <CancelTenderDialog
        tender={tender}
        open={cancelOpen}
        onOpenChange={setCancelOpen}
      />

      <ExtendDeadlineDialog
        tender={tender}
        open={extendOpen}
        onOpenChange={setExtendOpen}
      />
    </motion.div>
  )
}
