"use client"

/**
 * AuditLogsDataTable — paginated audit log viewer with advanced filters.
 *
 * Features:
 * - Filter controls: user (text), action type (select), entity type (select),
 *   date range (date pickers), IP address (text)
 * - Columns: timestamp, user, role, action type, entity type, entity ID, IP
 * - Server-side pagination
 * - Export to CSV (filtered results)
 * - Loading skeleton and empty/error states
 *
 * Validates: Requirements 17.7, 22.6
 */

import { useState, useCallback, useTransition } from "react"
import { useForm } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"
import {
  Search,
  FilterX,
  Download,
  Loader2,
  RefreshCw,
  ShieldCheck,
} from "lucide-react"

import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Badge } from "@/components/ui/badge"
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
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table"

import { useAuditLogs, useExportAuditLogs } from "@/hooks/useAuditLogs"
import {
  auditLogFilterSchema,
  ACTION_TYPES,
  ACTION_TYPE_LABELS,
  ENTITY_TYPES,
  ENTITY_TYPE_LABELS,
  type AuditLogFilterFormData,
} from "@/lib/validations/auditLogs"
import type { AuditLogQueryParams } from "@/lib/api/auditLogs"
import type { AuditLog } from "@/types/models.types"

// ─── Constants ────────────────────────────────────────────────────────────────

const PER_PAGE = 20

// Action type badge colour mapping
const ACTION_BADGE_CLASSES: Record<string, string> = {
  create: "bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400",
  update: "bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400",
  delete: "bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400",
  login: "bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-400",
  logout: "bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300",
  login_failed: "bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-400",
  account_locked: "bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400",
  approve: "bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-400",
  reject: "bg-rose-100 text-rose-800 dark:bg-rose-900/30 dark:text-rose-400",
  submit: "bg-sky-100 text-sky-800 dark:bg-sky-900/30 dark:text-sky-400",
}

function getActionBadgeClass(actionType: string): string {
  return (
    ACTION_BADGE_CLASSES[actionType] ??
    "bg-muted text-muted-foreground"
  )
}

// ─── Helpers ──────────────────────────────────────────────────────────────────

function formatTimestamp(iso: string): string {
  return new Intl.DateTimeFormat("en-US", {
    year: "numeric",
    month: "short",
    day: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
    second: "2-digit",
    hour12: false,
    timeZoneName: "short",
  }).format(new Date(iso))
}

function truncateUuid(id: string | null): string {
  if (!id) return "—"
  // Show first 8 chars of UUID
  return id.length > 8 ? `${id.slice(0, 8)}…` : id
}

// ─── Skeleton rows ────────────────────────────────────────────────────────────

function SkeletonRows() {
  return (
    <>
      {Array.from({ length: 8 }).map((_, i) => (
        <TableRow key={i}>
          <TableCell><Skeleton className="h-4 w-36" /></TableCell>
          <TableCell>
            <Skeleton className="h-4 w-28 mb-1" />
            <Skeleton className="h-3 w-20" />
          </TableCell>
          <TableCell><Skeleton className="h-5 w-20 rounded-full" /></TableCell>
          <TableCell><Skeleton className="h-5 w-24 rounded-full" /></TableCell>
          <TableCell><Skeleton className="h-4 w-20" /></TableCell>
          <TableCell><Skeleton className="h-4 w-24 font-mono" /></TableCell>
          <TableCell><Skeleton className="h-4 w-28 font-mono" /></TableCell>
        </TableRow>
      ))}
    </>
  )
}

// ─── Row ──────────────────────────────────────────────────────────────────────

function AuditLogRow({ log }: { log: AuditLog }) {
  const actionLabel =
    ACTION_TYPE_LABELS[log.action_type as keyof typeof ACTION_TYPE_LABELS] ??
    log.action_type

  const entityLabel =
    ENTITY_TYPE_LABELS[log.entity_type as keyof typeof ENTITY_TYPE_LABELS] ??
    log.entity_type

  return (
    <TableRow>
      {/* Timestamp */}
      <TableCell className="text-xs text-muted-foreground whitespace-nowrap font-mono">
        {formatTimestamp(log.created_at)}
      </TableCell>

      {/* User */}
      <TableCell>
        {log.user_id ? (
          <div>
            <p className="text-sm font-medium leading-none font-mono text-xs">
              {truncateUuid(log.user_id)}
            </p>
          </div>
        ) : (
          <span className="text-xs text-muted-foreground">System</span>
        )}
      </TableCell>

      {/* Role */}
      <TableCell>
        {log.user_role ? (
          <Badge variant="secondary" className="text-xs font-normal">
            {log.user_role.replace(/_/g, " ")}
          </Badge>
        ) : (
          <span className="text-xs text-muted-foreground">—</span>
        )}
      </TableCell>

      {/* Action type */}
      <TableCell>
        <span
          className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${getActionBadgeClass(log.action_type)}`}
        >
          {actionLabel}
        </span>
      </TableCell>

      {/* Entity type */}
      <TableCell className="text-sm text-muted-foreground">
        {entityLabel}
      </TableCell>

      {/* Entity ID */}
      <TableCell className="font-mono text-xs text-muted-foreground">
        {truncateUuid(log.entity_id)}
      </TableCell>

      {/* IP address */}
      <TableCell className="font-mono text-xs text-muted-foreground whitespace-nowrap">
        {log.ip_address || "—"}
      </TableCell>
    </TableRow>
  )
}

// ─── Main component ───────────────────────────────────────────────────────────

const EMPTY_FILTERS: AuditLogFilterFormData = {
  user: "",
  action_type: "",
  entity_type: "",
  ip_address: "",
  date_from: "",
  date_to: "",
}

export function AuditLogsDataTable() {
  const [page, setPage] = useState(1)
  const [appliedFilters, setAppliedFilters] =
    useState<AuditLogFilterFormData>(EMPTY_FILTERS)
  const [, startTransition] = useTransition()

  // ── React Hook Form ──────────────────────────────────────────────────────
  const {
    register,
    handleSubmit,
    setValue,
    watch,
    reset,
    formState: { errors },
  } = useForm<AuditLogFilterFormData>({
    resolver: zodResolver(auditLogFilterSchema),
    defaultValues: EMPTY_FILTERS,
  })

  const watchedActionType = watch("action_type")
  const watchedEntityType = watch("entity_type")

  // ── Build API params from applied filters ────────────────────────────────
  const queryParams: AuditLogQueryParams = {
    page,
    per_page: PER_PAGE,
    sort_by: "created_at",
    sort_dir: "desc",
    user: appliedFilters.user || undefined,
    action_type: appliedFilters.action_type || undefined,
    entity_type: appliedFilters.entity_type || undefined,
    ip_address: appliedFilters.ip_address || undefined,
    date_from: appliedFilters.date_from || undefined,
    date_to: appliedFilters.date_to || undefined,
  }

  const { data, isLoading, isError, refetch } = useAuditLogs(queryParams)
  const exportMutation = useExportAuditLogs()

  const logs = data?.data ?? []
  const meta = data?.meta

  // ── Handlers ─────────────────────────────────────────────────────────────

  const onSubmit = useCallback(
    (values: AuditLogFilterFormData) => {
      startTransition(() => {
        setAppliedFilters(values)
        setPage(1)
      })
    },
    [],
  )

  const handleClearFilters = useCallback(() => {
    reset(EMPTY_FILTERS)
    startTransition(() => {
      setAppliedFilters(EMPTY_FILTERS)
      setPage(1)
    })
  }, [reset])

  const handleExportCsv = useCallback(() => {
    exportMutation.mutate({
      user: appliedFilters.user || undefined,
      action_type: appliedFilters.action_type || undefined,
      entity_type: appliedFilters.entity_type || undefined,
      ip_address: appliedFilters.ip_address || undefined,
      date_from: appliedFilters.date_from || undefined,
      date_to: appliedFilters.date_to || undefined,
    })
  }, [exportMutation, appliedFilters])

  const hasActiveFilters =
    Object.entries(appliedFilters).some(([, v]) => Boolean(v))

  return (
    <div className="space-y-5">

      {/* ── Filter form ──────────────────────────────────────────────────── */}
      <form
        onSubmit={handleSubmit(onSubmit)}
        noValidate
        aria-label="Audit log filters"
        className="rounded-xl border border-border bg-card p-4 space-y-4"
      >
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">

          {/* User search */}
          <div className="space-y-1.5 xl:col-span-2">
            <Label htmlFor="filter-user" className="text-xs">
              User (name or ID)
            </Label>
            <div className="relative">
              <Search className="absolute left-2.5 top-2.5 size-3.5 text-muted-foreground pointer-events-none" />
              <Input
                id="filter-user"
                placeholder="Search user…"
                className="pl-8 h-9 text-sm"
                aria-describedby={errors.user ? "user-error" : undefined}
                {...register("user")}
              />
            </div>
            {errors.user && (
              <p id="user-error" className="text-xs text-destructive">
                {errors.user.message}
              </p>
            )}
          </div>

          {/* Action type */}
          <div className="space-y-1.5">
            <Label className="text-xs">Action Type</Label>
            <Select
              value={watchedActionType || "__all__"}
              onValueChange={(v) =>
                setValue("action_type", v === "__all__" ? "" : v)
              }
            >
              <SelectTrigger className="h-9 text-sm" aria-label="Filter by action type">
                <SelectValue placeholder="All actions" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="__all__">All actions</SelectItem>
                {ACTION_TYPES.map((type) => (
                  <SelectItem key={type} value={type}>
                    {ACTION_TYPE_LABELS[type]}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          {/* Entity type */}
          <div className="space-y-1.5">
            <Label className="text-xs">Entity Type</Label>
            <Select
              value={watchedEntityType || "__all__"}
              onValueChange={(v) =>
                setValue("entity_type", v === "__all__" ? "" : v)
              }
            >
              <SelectTrigger className="h-9 text-sm" aria-label="Filter by entity type">
                <SelectValue placeholder="All entities" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="__all__">All entities</SelectItem>
                {ENTITY_TYPES.map((type) => (
                  <SelectItem key={type} value={type}>
                    {ENTITY_TYPE_LABELS[type]}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          {/* Date from */}
          <div className="space-y-1.5">
            <Label htmlFor="filter-date-from" className="text-xs">
              From
            </Label>
            <Input
              id="filter-date-from"
              type="date"
              className="h-9 text-sm"
              aria-describedby={errors.date_from ? "date-from-error" : undefined}
              {...register("date_from")}
            />
            {errors.date_from && (
              <p id="date-from-error" className="text-xs text-destructive">
                {errors.date_from.message}
              </p>
            )}
          </div>

          {/* Date to */}
          <div className="space-y-1.5">
            <Label htmlFor="filter-date-to" className="text-xs">
              To
            </Label>
            <Input
              id="filter-date-to"
              type="date"
              className="h-9 text-sm"
              aria-describedby={errors.date_to ? "date-to-error" : undefined}
              {...register("date_to")}
            />
            {errors.date_to && (
              <p id="date-to-error" className="text-xs text-destructive">
                {errors.date_to.message}
              </p>
            )}
          </div>
        </div>

        {/* Second row: IP + actions */}
        <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
          {/* IP address */}
          <div className="space-y-1.5 sm:w-52">
            <Label htmlFor="filter-ip" className="text-xs">
              IP Address
            </Label>
            <Input
              id="filter-ip"
              placeholder="e.g. 192.168.1.1"
              className="h-9 text-sm font-mono"
              aria-describedby={errors.ip_address ? "ip-error" : undefined}
              {...register("ip_address")}
            />
            {errors.ip_address && (
              <p id="ip-error" className="text-xs text-destructive">
                {errors.ip_address.message}
              </p>
            )}
          </div>

          {/* Apply / Clear */}
          <div className="flex items-center gap-2 sm:ml-auto">
            {hasActiveFilters && (
              <Button
                type="button"
                variant="ghost"
                size="sm"
                onClick={handleClearFilters}
                className="h-9 gap-1.5 text-muted-foreground"
                aria-label="Clear all filters"
              >
                <FilterX className="size-3.5" />
                Clear
              </Button>
            )}
            <Button type="submit" size="sm" className="h-9">
              Apply Filters
            </Button>
          </div>
        </div>
      </form>

      {/* ── Toolbar: result count + CSV export ───────────────────────────── */}
      <div className="flex items-center justify-between gap-4">
        <p className="text-sm text-muted-foreground">
          {isLoading
            ? "Loading…"
            : meta
              ? `${meta.total.toLocaleString()} record${meta.total !== 1 ? "s" : ""}`
              : ""}
        </p>

        <Button
          variant="outline"
          size="sm"
          onClick={handleExportCsv}
          disabled={exportMutation.isPending || isLoading}
          aria-label="Export audit logs as CSV"
          className="gap-1.5"
        >
          {exportMutation.isPending ? (
            <Loader2 className="size-3.5 animate-spin" />
          ) : (
            <Download className="size-3.5" />
          )}
          {exportMutation.isPending ? "Exporting…" : "Export CSV"}
        </Button>
      </div>

      {/* ── Table ────────────────────────────────────────────────────────── */}
      <div className="rounded-xl border border-border bg-card overflow-x-auto">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead className="whitespace-nowrap">Timestamp (UTC)</TableHead>
              <TableHead>User ID</TableHead>
              <TableHead>Role</TableHead>
              <TableHead>Action</TableHead>
              <TableHead>Entity Type</TableHead>
              <TableHead>Entity ID</TableHead>
              <TableHead>IP Address</TableHead>
            </TableRow>
          </TableHeader>

          <TableBody>
            {isLoading && <SkeletonRows />}

            {isError && (
              <TableRow>
                <TableCell
                  colSpan={7}
                  className="py-12 text-center text-muted-foreground"
                >
                  <p className="mb-3">Failed to load audit logs.</p>
                  <Button
                    variant="outline"
                    size="sm"
                    onClick={() => refetch()}
                    className="gap-1.5"
                  >
                    <RefreshCw className="size-3.5" />
                    Retry
                  </Button>
                </TableCell>
              </TableRow>
            )}

            {!isLoading && !isError && logs.length === 0 && (
              <TableRow>
                <TableCell
                  colSpan={7}
                  className="py-16 text-center"
                >
                  <div className="flex flex-col items-center gap-2 text-muted-foreground">
                    <ShieldCheck
                      className="size-10 text-muted-foreground/30"
                      aria-hidden="true"
                    />
                    <p className="text-sm font-medium">No audit logs found</p>
                    <p className="text-xs text-muted-foreground/70">
                      {hasActiveFilters
                        ? "Try adjusting your filters"
                        : "Audit events will appear here as they occur"}
                    </p>
                  </div>
                </TableCell>
              </TableRow>
            )}

            {!isLoading &&
              !isError &&
              logs.map((log) => <AuditLogRow key={log.id} log={log} />)}
          </TableBody>
        </Table>
      </div>

      {/* ── Pagination ───────────────────────────────────────────────────── */}
      {meta && meta.last_page > 1 && (
        <div className="flex items-center justify-between text-sm text-muted-foreground">
          <span>
            Showing {meta.from ?? 0}–{meta.to ?? 0} of{" "}
            {meta.total.toLocaleString()} records
          </span>

          <div className="flex items-center gap-2">
            <Button
              variant="outline"
              size="sm"
              onClick={() => setPage((p) => Math.max(1, p - 1))}
              disabled={page === 1 || isLoading}
              aria-label="Previous page"
            >
              ← Previous
            </Button>
            <span className="px-1 text-xs">
              Page {meta.current_page} of {meta.last_page}
            </span>
            <Button
              variant="outline"
              size="sm"
              onClick={() => setPage((p) => Math.min(meta.last_page, p + 1))}
              disabled={page === meta.last_page || isLoading}
              aria-label="Next page"
            >
              Next →
            </Button>
          </div>
        </div>
      )}
    </div>
  )
}
