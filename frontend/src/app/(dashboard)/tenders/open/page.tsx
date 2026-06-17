"use client"

/**
 * Supplier-facing Open Tenders list page.
 *
 * Accessible at /tenders/open (within the dashboard route group).
 * Visible to Supplier role only — shows all published tenders they can bid on.
 *
 * Features:
 * - Paginated list of published tenders
 * - Filter by search, category
 * - Deadline countdown badge
 * - Bid status indicator (already bid / not bid)
 * - Link to tender detail / bid submission
 *
 * Validates: Requirements 8.1, 8.3, 22.6
 */

import { useState } from "react"
import Link from "next/link"
import { RefreshCw, Clock, Search } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Badge } from "@/components/ui/badge"
import { Skeleton } from "@/components/ui/skeleton"
import { Alert, AlertDescription } from "@/components/ui/alert"
import { Card, CardContent } from "@/components/ui/card"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { useOpenTenders } from "@/hooks/useTenders"
import { TENDER_CATEGORIES } from "@/lib/validations/tenders"
import { formatCurrency } from "@/lib/utils"
import type { OpenTender } from "@/types/tender"

// ─── Countdown helper ─────────────────────────────────────────────────────────

function getCountdown(deadline: string): {
  label: string
  urgent: boolean
  expired: boolean
} {
  const diff = new Date(deadline).getTime() - Date.now()
  if (diff <= 0) return { label: "Deadline passed", urgent: false, expired: true }

  const days = Math.floor(diff / (1000 * 60 * 60 * 24))
  const hours = Math.floor((diff % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60))
  const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60))

  let label: string
  if (days > 0) {
    label = `${days}d ${hours}h remaining`
  } else if (hours > 0) {
    label = `${hours}h ${minutes}m remaining`
  } else {
    label = `${minutes}m remaining`
  }

  return { label, urgent: days < 2, expired: false }
}

function formatDeadline(iso: string): string {
  return new Intl.DateTimeFormat("en-US", {
    dateStyle: "medium",
    timeStyle: "short",
  }).format(new Date(iso))
}

// ─── Tender card ──────────────────────────────────────────────────────────────

function OpenTenderCard({ tender }: { tender: OpenTender }) {
  const countdown = getCountdown(tender.submission_deadline)
  const hasBid = Boolean(tender.my_bid)

  return (
    <Card
      className="group transition-shadow hover:shadow-md"
      aria-label={`Tender ${tender.reference_number}: ${tender.title}`}
    >
      <CardContent className="p-5">
        <div className="flex flex-wrap items-start justify-between gap-3">
          {/* Left: reference + title + category */}
          <div className="min-w-0 flex-1 space-y-1">
            <div className="flex flex-wrap items-center gap-2">
              <span className="font-mono text-xs font-medium text-muted-foreground">
                {tender.reference_number}
              </span>
              <Badge variant="outline" className="capitalize text-xs">
                {tender.tender_type.replace(/_/g, " ")}
              </Badge>
              {hasBid && (
                <Badge variant="secondary" className="text-xs">
                  Bid Submitted
                </Badge>
              )}
            </div>

            <h2 className="text-base font-semibold leading-snug">
              {tender.title}
            </h2>
            <p className="text-sm text-muted-foreground">{tender.category}</p>
          </div>

          {/* Right: value + actions */}
          <div className="flex flex-col items-end gap-2">
            <p className="text-base font-semibold tabular-nums">
              {formatCurrency(tender.estimated_value, tender.currency ?? "USD")}
            </p>
            <Link
              href={`/tenders/open/${tender.id}`}
              className="inline-flex h-8 items-center gap-1.5 rounded-md bg-primary px-3 text-xs font-medium text-primary-foreground transition-opacity hover:opacity-90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
              aria-label={`${hasBid ? "View / revise bid for" : "Submit bid for"} ${tender.reference_number}`}
            >
              {hasBid ? "View / Revise Bid" : "Submit Bid"}
            </Link>
          </div>
        </div>

        {/* Deadline row */}
        <div className="mt-3 flex items-center gap-3 border-t border-border pt-3 text-xs">
          <span className="flex items-center gap-1 text-muted-foreground">
            <Clock className="size-3.5 shrink-0" aria-hidden="true" />
            {formatDeadline(tender.submission_deadline)}
          </span>
          <span
            className={`font-medium ${
              countdown.expired
                ? "text-destructive"
                : countdown.urgent
                  ? "text-amber-600 dark:text-amber-400"
                  : "text-emerald-600 dark:text-emerald-400"
            }`}
          >
            {countdown.label}
          </span>
        </div>

        {/* Description preview */}
        <p className="mt-2 line-clamp-2 text-xs text-muted-foreground">
          {tender.description}
        </p>
      </CardContent>
    </Card>
  )
}

// ─── Loading skeleton ─────────────────────────────────────────────────────────

function TenderCardSkeleton() {
  return (
    <Card>
      <CardContent className="p-5 space-y-3">
        <div className="flex justify-between gap-3">
          <div className="space-y-2 flex-1">
            <Skeleton className="h-4 w-32" />
            <Skeleton className="h-5 w-3/4" />
            <Skeleton className="h-4 w-24" />
          </div>
          <div className="space-y-2">
            <Skeleton className="h-5 w-24" />
            <Skeleton className="h-8 w-28" />
          </div>
        </div>
        <Skeleton className="h-px w-full" />
        <Skeleton className="h-4 w-48" />
      </CardContent>
    </Card>
  )
}

// ─── Page component ───────────────────────────────────────────────────────────

export default function OpenTendersPage() {
  const [search, setSearch] = useState("")
  const [category, setCategory] = useState("")
  const [page, setPage] = useState(1)

  const { data, isLoading, isError, refetch } = useOpenTenders({
    page,
    per_page: 12,
    search: search || undefined,
    category: category || undefined,
  })

  const tenders = data?.data ?? []
  const meta = data?.meta

  function handleSearch(e: React.ChangeEvent<HTMLInputElement>) {
    setSearch(e.target.value)
    setPage(1)
  }

  return (
    <div className="space-y-6">
      {/* Page header */}
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">Open Tenders</h1>
        <p className="mt-1 text-sm text-muted-foreground">
          Browse published tenders and submit your bids.
        </p>
      </div>

      {/* Filters */}
      <div className="flex flex-wrap items-center gap-3">
        <div className="relative flex-1 min-w-56 max-w-sm">
          <Search
            className="absolute left-2.5 top-1/2 -translate-y-1/2 size-4 text-muted-foreground"
            aria-hidden="true"
          />
          <Input
            placeholder="Search tenders…"
            value={search}
            onChange={handleSearch}
            className="h-9 pl-9"
            aria-label="Search tenders"
          />
        </div>

        <Select
          value={category}
          onValueChange={(v) => {
            setCategory(v)
            setPage(1)
          }}
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

        {meta && (
          <span className="ml-auto text-sm text-muted-foreground">
            {meta.total} tender{meta.total !== 1 ? "s" : ""} found
          </span>
        )}
      </div>

      {/* Error */}
      {isError && (
        <Alert variant="destructive" role="alert">
          <AlertDescription className="flex items-center justify-between">
            Failed to load open tenders.
            <Button
              variant="outline"
              size="sm"
              onClick={() => refetch()}
              aria-label="Retry"
            >
              <RefreshCw className="size-3.5" aria-hidden="true" />
              Retry
            </Button>
          </AlertDescription>
        </Alert>
      )}

      {/* Tender cards */}
      {isLoading ? (
        <div className="grid gap-4 sm:grid-cols-1 lg:grid-cols-2">
          {Array.from({ length: 6 }).map((_, i) => (
            <TenderCardSkeleton key={i} />
          ))}
        </div>
      ) : tenders.length === 0 ? (
        <div className="flex flex-col items-center gap-3 py-16 text-center">
          <p className="text-sm text-muted-foreground">
            No open tenders found
            {search || category ? " matching your filters" : ""}.
          </p>
          {(search || category) && (
            <Button
              variant="outline"
              size="sm"
              onClick={() => {
                setSearch("")
                setCategory("")
                setPage(1)
              }}
            >
              Clear filters
            </Button>
          )}
        </div>
      ) : (
        <div className="grid gap-4 sm:grid-cols-1 lg:grid-cols-2">
          {tenders.map((tender) => (
            <OpenTenderCard key={tender.id} tender={tender} />
          ))}
        </div>
      )}

      {/* Pagination */}
      {meta && meta.last_page > 1 && (
        <div className="flex items-center justify-between text-sm text-muted-foreground">
          <span>
            {meta.from ?? 0}–{meta.to ?? 0} of {meta.total}
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
            <span className="px-1 text-xs">
              {page} / {meta.last_page}
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
    </div>
  )
}
