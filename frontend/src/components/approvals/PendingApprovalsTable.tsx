"use client"

/**
 * PendingApprovalsTable — paginated table of pending approval items.
 *
 * Features:
 * - Columns: Document Type badge, Document Reference, Level, Waiting Since,
 *   Approve / Reject / Return action buttons
 * - Auto-refetches every 30 seconds (via usePendingApprovals)
 * - Skeleton loading, error state with Retry, empty state message
 * - Filter by document_type
 *
 * Validates: Requirements 22.5
 */

import { useState, useCallback, useTransition } from "react"
import { CheckCircle2, XCircle, RotateCcw, RefreshCw } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
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
import { ApproveDialog } from "./ApproveDialog"
import { RejectDialog } from "./RejectDialog"
import { ReturnForRevisionDialog } from "./ReturnForRevisionDialog"
import { usePendingApprovals } from "@/hooks/useApprovals"
import {
  DOCUMENT_TYPE_LABELS,
  DOCUMENT_TYPES,
  type DocumentType,
} from "@/lib/validations/approvalWorkflows"
import type { Approval } from "@/types/models.types"

// ─── Helpers ──────────────────────────────────────────────────────────────────

function DocumentTypeBadge({ docType }: { docType: string }) {
  const label =
    DOCUMENT_TYPE_LABELS[docType as DocumentType] ?? docType
  return <Badge variant="secondary">{label}</Badge>
}

function WaitingSince({ createdAt }: { createdAt: string }) {
  const created = new Date(createdAt)
  const now = new Date()
  const diffMs = now.getTime() - created.getTime()
  const diffHours = Math.floor(diffMs / (1000 * 60 * 60))
  const diffDays = Math.floor(diffHours / 24)

  let label: string
  if (diffDays > 0) {
    label = `${diffDays}d ${diffHours % 24}h`
  } else if (diffHours > 0) {
    label = `${diffHours}h`
  } else {
    const diffMins = Math.floor(diffMs / (1000 * 60))
    label = `${diffMins}m`
  }

  return (
    <span
      title={new Intl.DateTimeFormat("en-US", {
        month: "short",
        day: "numeric",
        year: "numeric",
        hour: "2-digit",
        minute: "2-digit",
      }).format(created)}
      className="text-sm text-muted-foreground"
    >
      {label}
    </span>
  )
}

function SkeletonRows() {
  return (
    <>
      {Array.from({ length: 6 }).map((_, i) => (
        <TableRow key={i}>
          <TableCell><Skeleton className="h-5 w-28 rounded-full" /></TableCell>
          <TableCell><Skeleton className="h-4 w-36" /></TableCell>
          <TableCell><Skeleton className="h-4 w-20" /></TableCell>
          <TableCell><Skeleton className="h-4 w-16" /></TableCell>
          <TableCell><Skeleton className="h-8 w-28" /></TableCell>
        </TableRow>
      ))}
    </>
  )
}

// ─── Main component ───────────────────────────────────────────────────────────

const DOC_TYPE_FILTER_OPTIONS = [
  { value: "all", label: "All Types" },
  ...DOCUMENT_TYPES.map((dt) => ({ value: dt, label: DOCUMENT_TYPE_LABELS[dt] })),
]

type DialogType = "approve" | "reject" | "return" | null

interface ActiveDialog {
  type: DialogType
  approval: Approval
}

export function PendingApprovalsTable() {
  const [page, setPage] = useState(1)
  const [docTypeFilter, setDocTypeFilter] = useState("all")
  const [activeDialog, setActiveDialog] = useState<ActiveDialog | null>(null)

  const [, startTransition] = useTransition()

  const queryParams = {
    page,
    per_page: 15,
    document_type: docTypeFilter !== "all" ? docTypeFilter : undefined,
    sort_by: "created_at",
    sort_dir: "asc" as const, // oldest first — most urgent at top
  }

  const { data, isLoading, isError, refetch } = usePendingApprovals(queryParams)

  const approvals = data?.data ?? []
  const meta = data?.meta

  const openDialog = useCallback(
    (type: DialogType, approval: Approval) => {
      setActiveDialog({ type, approval })
    },
    [],
  )

  const closeDialog = useCallback(() => {
    setActiveDialog(null)
  }, [])

  const handleActionSuccess = useCallback(() => {
    closeDialog()
    startTransition(() => {
      refetch()
    })
  }, [closeDialog, refetch, startTransition])

  return (
    <div className="space-y-4">
      {/* Toolbar */}
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex items-center gap-2">
          <Select
            value={docTypeFilter}
            onValueChange={(val) => {
              setDocTypeFilter(val)
              setPage(1)
            }}
          >
            <SelectTrigger className="w-48" aria-label="Filter by document type">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {DOC_TYPE_FILTER_OPTIONS.map((opt) => (
                <SelectItem key={opt.value} value={opt.value}>
                  {opt.label}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>

        <Button
          variant="outline"
          size="sm"
          onClick={() => refetch()}
          disabled={isLoading}
          aria-label="Refresh pending approvals"
        >
          <RefreshCw className={`size-3.5 ${isLoading ? "animate-spin" : ""}`} />
          Refresh
        </Button>
      </div>

      {/* Table */}
      <div className="rounded-xl border border-border bg-card">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Document Type</TableHead>
              <TableHead>Document Reference</TableHead>
              <TableHead>Level</TableHead>
              <TableHead>Waiting Since</TableHead>
              <TableHead className="w-40">Actions</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {isLoading && <SkeletonRows />}

            {isError && (
              <TableRow>
                <TableCell colSpan={5} className="py-10 text-center text-muted-foreground">
                  <p className="mb-2">Failed to load pending approvals.</p>
                  <Button variant="outline" size="sm" onClick={() => refetch()}>
                    <RefreshCw className="size-3.5" />
                    Retry
                  </Button>
                </TableCell>
              </TableRow>
            )}

            {!isLoading && !isError && approvals.length === 0 && (
              <TableRow>
                <TableCell colSpan={5} className="py-10 text-center text-muted-foreground">
                  {docTypeFilter !== "all"
                    ? "No pending approvals for the selected document type."
                    : "You have no pending approvals at this time."}
                </TableCell>
              </TableRow>
            )}

            {!isLoading &&
              !isError &&
              approvals.map((approval) => (
                <TableRow key={approval.id}>
                  <TableCell>
                    <DocumentTypeBadge docType={approval.document_type} />
                  </TableCell>

                  <TableCell>
                    <span className="font-mono text-sm">{approval.document_id}</span>
                  </TableCell>

                  <TableCell className="text-sm text-muted-foreground">
                    {approval.level_id}
                  </TableCell>

                  <TableCell>
                    <WaitingSince createdAt={approval.created_at} />
                  </TableCell>

                  <TableCell>
                    <div className="flex items-center gap-1">
                      <Button
                        variant="ghost"
                        size="icon-sm"
                        aria-label="Approve"
                        title="Approve"
                        onClick={() => openDialog("approve", approval)}
                        className="text-green-700 hover:bg-green-50 hover:text-green-800 dark:text-green-400 dark:hover:bg-green-900/20"
                      >
                        <CheckCircle2 className="size-4" />
                      </Button>
                      <Button
                        variant="ghost"
                        size="icon-sm"
                        aria-label="Reject"
                        title="Reject"
                        onClick={() => openDialog("reject", approval)}
                        className="text-destructive hover:bg-destructive/10 hover:text-destructive"
                      >
                        <XCircle className="size-4" />
                      </Button>
                      <Button
                        variant="ghost"
                        size="icon-sm"
                        aria-label="Return for revision"
                        title="Return for revision"
                        onClick={() => openDialog("return", approval)}
                        className="text-amber-700 hover:bg-amber-50 hover:text-amber-800 dark:text-amber-400 dark:hover:bg-amber-900/20"
                      >
                        <RotateCcw className="size-4" />
                      </Button>
                    </div>
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
            Showing {meta.from ?? 0}–{meta.to ?? 0} of {meta.total} pending approvals
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

      {/* Action dialogs */}
      {activeDialog?.type === "approve" && (
        <ApproveDialog
          approval={activeDialog.approval}
          open
          onOpenChange={(open) => !open && closeDialog()}
          onSuccess={handleActionSuccess}
        />
      )}
      {activeDialog?.type === "reject" && (
        <RejectDialog
          approval={activeDialog.approval}
          open
          onOpenChange={(open) => !open && closeDialog()}
          onSuccess={handleActionSuccess}
        />
      )}
      {activeDialog?.type === "return" && (
        <ReturnForRevisionDialog
          approval={activeDialog.approval}
          open
          onOpenChange={(open) => !open && closeDialog()}
          onSuccess={handleActionSuccess}
        />
      )}
    </div>
  )
}
