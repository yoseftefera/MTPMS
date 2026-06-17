"use client"

/**
 * TendersDataTable — full data table for Procurement Officer tender management.
 *
 * Features:
 * - Filterable by search, status, tender type, category
 * - Paginated results
 * - Inline publish action on draft tenders
 * - Edit and Cancel actions with dialog support
 * - New Tender creation via TenderForm dialog
 *
 * Validates: Requirements 8.1, 8.3, 22.6
 */

import { useState } from "react"
import Link from "next/link"
import { RefreshCw, Plus } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Badge } from "@/components/ui/badge"
import { Skeleton } from "@/components/ui/skeleton"
import { Alert, AlertDescription } from "@/components/ui/alert"
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
import { TenderStatusBadge } from "@/components/tenders/TenderStatusBadge"
import { TenderForm } from "@/components/tenders/TenderForm"
import { CancelTenderDialog } from "@/components/tenders/CancelTenderDialog"
import { useTenders, usePublishTender } from "@/hooks/useTenders"
import { TENDER_CATEGORIES } from "@/lib/validations/tenders"
import type { TenderDetail, TenderFilters } from "@/types/tender"

// ─── Helpers ──────────────────────────────────────────────────────────────────

function formatDate(iso: string) {
  return new Intl.DateTimeFormat("en-US", {
    month: "short",
    day: "numeric",
    year: "numeric",
  }).format(new Date(iso))
}

function isPast(iso: string): boolean {
  return new Date(iso) < new Date()
}

// ─── Type badge ───────────────────────────────────────────────────────────────

type BadgeVariant = "default" | "secondary" | "warning" | "outline"

const TYPE_VARIANT: Record<string, BadgeVariant> = {
  open: "default",
  restricted: "warning",
  single_source: "secondary",
}

function TenderTypeBadge({ type }: { type: string }) {
  const variant: BadgeVariant = TYPE_VARIANT[type] ?? "outline"
  const label = type.replace(/_/g, " ")
  return (
    <Badge variant={variant} className="capitalize text-xs">
      {label}
    </Badge>
  )
}

// ─── Loading skeleton ─────────────────────────────────────────────────────────

function TableSkeleton() {
  return (
    <>
      {Array.from({ length: 5 }).map((_, i) => (
        <TableRow key={i}>
          {Array.from({ length: 8 }).map((__, j) => (
            <TableCell key={j}>
              <Skeleton className="h-5 w-full" />
            </TableCell>
          ))}
        </TableRow>
      ))}
    </>
  )
}

// ─── Component ────────────────────────────────────────────────────────────────

export function TendersDataTable() {
  const [filters, setFilters] = useState<TenderFilters>({
    page: 1,
    per_page: 20,
    search: "",
    status: "",
    tender_type: "",
    category: "",
  })

  const [tenderFormOpen, setTenderFormOpen] = useState(false)
  const [editTender, setEditTender] = useState<TenderDetail | undefined>(undefined)
  const [publishConfirmId, setPublishConfirmId] = useState<string | null>(null)
  const [cancelDialogTender, setCancelDialogTender] = useState<TenderDetail | undefined>(undefined)

  const { data, isLoading, isError, refetch } = useTenders(filters)
  const publishTender = usePublishTender()

  const tenders = data?.data ?? []
  const meta = data?.meta

  function handleSearch(e: React.ChangeEvent<HTMLInputElement>) {
    setFilters((prev) => ({ ...prev, search: e.target.value, page: 1 }))
  }

  function handlePublish(tender: TenderDetail) {
    setPublishConfirmId(tender.id)
    publishTender.mutate(tender.id, {
      onSettled: () => setPublishConfirmId(null),
    })
  }

  // ── Render ────────────────────────────────────────────────────────────────

  return (
    <div className="space-y-4">
      {/* Toolbar */}
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex flex-wrap items-center gap-3">
          {/* Search */}
          <Input
            placeholder="Search tenders…"
            value={filters.search ?? ""}
            onChange={handleSearch}
            className="h-9 w-64"
            aria-label="Search tenders"
          />

          {/* Status filter */}
          <Select
            value={filters.status ?? ""}
            onValueChange={(v) =>
              setFilters((prev) => ({
                ...prev,
                status: v as TenderFilters["status"],
                page: 1,
              }))
            }
          >
            <SelectTrigger className="h-9 w-40" aria-label="Filter by status">
              <SelectValue placeholder="All Statuses" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="">All Statuses</SelectItem>
              <SelectItem value="draft">Draft</SelectItem>
              <SelectItem value="published">Published</SelectItem>
              <SelectItem value="closed">Closed</SelectItem>
              <SelectItem value="awarded">Awarded</SelectItem>
              <SelectItem value="cancelled">Cancelled</SelectItem>
            </SelectContent>
          </Select>

          {/* Type filter */}
          <Select
            value={filters.tender_type ?? ""}
            onValueChange={(v) =>
              setFilters((prev) => ({
                ...prev,
                tender_type: v as TenderFilters["tender_type"],
                page: 1,
              }))
            }
          >
            <SelectTrigger className="h-9 w-40" aria-label="Filter by type">
              <SelectValue placeholder="All Types" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="">All Types</SelectItem>
              <SelectItem value="open">Open</SelectItem>
              <SelectItem value="restricted">Restricted</SelectItem>
              <SelectItem value="single_source">Single Source</SelectItem>
            </SelectContent>
          </Select>

          {/* Category filter */}
          <Select
            value={filters.category ?? ""}
            onValueChange={(v) =>
              setFilters((prev) => ({ ...prev, category: v, page: 1 }))
            }
          >
            <SelectTrigger className="h-9 w-52" aria-label="Filter by category">
              <SelectValue placeholder="All Categories" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="">All Categories</SelectItem>
              {TENDER_CATEGORIES.map((cat) => (
                <SelectItem key={cat} value={cat}>
                  {cat}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>

        {/* New Tender button */}
        <Button
          size="sm"
          onClick={() => {
            setEditTender(undefined)
            setTenderFormOpen(true)
          }}
          aria-label="Create new tender"
        >
          <Plus className="size-4" aria-hidden="true" />
          New Tender
        </Button>
      </div>

      {/* Error state */}
      {isError && (
        <Alert variant="destructive" role="alert">
          <AlertDescription className="flex items-center justify-between">
            Failed to load tenders.
            <Button
              variant="outline"
              size="sm"
              onClick={() => refetch()}
              aria-label="Retry loading tenders"
            >
              <RefreshCw className="size-3.5" aria-hidden="true" />
              Retry
            </Button>
          </AlertDescription>
        </Alert>
      )}

      {/* Table */}
      <div className="rounded-xl border border-border">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Reference</TableHead>
              <TableHead>Title</TableHead>
              <TableHead>Category</TableHead>
              <TableHead>Type</TableHead>
              <TableHead>Status</TableHead>
              <TableHead>Deadline</TableHead>
              <TableHead className="text-right">Bids</TableHead>
              <TableHead className="w-40 text-right">Actions</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {isLoading ? (
              <TableSkeleton />
            ) : tenders.length === 0 ? (
              <TableRow>
                <TableCell
                  colSpan={8}
                  className="py-10 text-center text-sm text-muted-foreground"
                >
                  No tenders found.
                </TableCell>
              </TableRow>
            ) : (
              tenders.map((tender) => {
                const deadlinePast = isPast(tender.submission_deadline)
                const isPublishing =
                  publishTender.isPending && publishConfirmId === tender.id

                return (
                  <TableRow key={tender.id}>
                    {/* Reference */}
                    <TableCell>
                      <span className="font-mono text-sm font-medium">
                        {tender.reference_number}
                      </span>
                    </TableCell>

                    {/* Title */}
                    <TableCell>
                      <span className="block max-w-48 truncate text-sm">
                        {tender.title}
                      </span>
                    </TableCell>

                    {/* Category */}
                    <TableCell className="text-sm text-muted-foreground">
                      {tender.category}
                    </TableCell>

                    {/* Type */}
                    <TableCell>
                      <TenderTypeBadge type={tender.tender_type} />
                    </TableCell>

                    {/* Status */}
                    <TableCell>
                      <TenderStatusBadge status={tender.status} />
                    </TableCell>

                    {/* Deadline */}
                    <TableCell>
                      <span
                        className={`text-sm ${
                          deadlinePast
                            ? "text-destructive font-medium"
                            : "text-muted-foreground"
                        }`}
                      >
                        {formatDate(tender.submission_deadline)}
                      </span>
                    </TableCell>

                    {/* Bids count */}
                    <TableCell className="text-right tabular-nums text-sm">
                      {tender.bids_count ?? 0}
                    </TableCell>

                    {/* Actions */}
                    <TableCell className="text-right">
                      <div className="flex items-center justify-end gap-1.5">
                        <Link
                          href={`/tenders/${tender.id}`}
                          className="inline-flex h-7 items-center rounded-md border border-border px-2.5 text-xs font-medium hover:bg-muted transition-colors"
                          aria-label={`View tender ${tender.reference_number}`}
                        >
                          View
                        </Link>

                        {tender.status === "draft" && (
                          <>
                            <Button
                              variant="outline"
                              size="sm"
                              className="h-7 px-2.5 text-xs"
                              onClick={() => {
                                setEditTender(tender)
                                setTenderFormOpen(true)
                              }}
                              aria-label={`Edit tender ${tender.reference_number}`}
                            >
                              Edit
                            </Button>
                            <Button
                              variant="default"
                              size="sm"
                              className="h-7 px-2.5 text-xs"
                              disabled={isPublishing}
                              onClick={() => handlePublish(tender)}
                              aria-label={`Publish tender ${tender.reference_number}`}
                            >
                              {isPublishing ? "Publishing…" : "Publish"}
                            </Button>
                          </>
                        )}

                        {tender.status === "published" && (
                          <Button
                            variant="outline"
                            size="sm"
                            className="h-7 px-2.5 text-xs text-destructive border-destructive/30 hover:bg-destructive/5"
                            onClick={() => setCancelDialogTender(tender)}
                            aria-label={`Cancel tender ${tender.reference_number}`}
                          >
                            Cancel
                          </Button>
                        )}
                      </div>
                    </TableCell>
                  </TableRow>
                )
              })
            )}
          </TableBody>
        </Table>
      </div>

      {/* Pagination */}
      {meta && meta.last_page > 1 && (
        <div className="flex items-center justify-between text-sm text-muted-foreground">
          <span>
            {meta.from ?? 0}–{meta.to ?? 0} of {meta.total} tenders
          </span>
          <div className="flex items-center gap-2">
            <Button
              variant="outline"
              size="sm"
              disabled={!meta.from || (filters.page ?? 1) <= 1}
              onClick={() =>
                setFilters((prev) => ({ ...prev, page: (prev.page ?? 1) - 1 }))
              }
              aria-label="Previous page"
            >
              Previous
            </Button>
            <Button
              variant="outline"
              size="sm"
              disabled={(filters.page ?? 1) >= meta.last_page}
              onClick={() =>
                setFilters((prev) => ({ ...prev, page: (prev.page ?? 1) + 1 }))
              }
              aria-label="Next page"
            >
              Next
            </Button>
          </div>
        </div>
      )}

      {/* Dialogs */}
      <TenderForm
        open={tenderFormOpen}
        onOpenChange={setTenderFormOpen}
        tender={editTender}
        onSuccess={() => setEditTender(undefined)}
      />

      {cancelDialogTender && (
        <CancelTenderDialog
          tender={cancelDialogTender}
          open={!!cancelDialogTender}
          onOpenChange={(o) => {
            if (!o) setCancelDialogTender(undefined)
          }}
        />
      )}
    </div>
  )
}
