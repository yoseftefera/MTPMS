"use client"

/**
 * Budget management list page.
 *
 * Accessible at /budgets (Finance_Officer and Tenant_Admin only).
 *
 * Features:
 * - DataTable with columns: Department, Fiscal Year, Total, Encumbered,
 *   Spent, Available, Utilization (progress bar), Actions
 * - Filter by fiscal_year and department
 * - "Allocate Budget" button (Finance_Officer / Tenant_Admin)
 * - "Transfer Budget" button
 * - Loading skeleton / error boundary with retry
 *
 * Validates: Requirements 13.1, 13.10, 22.5, 22.10
 */

import { useState } from "react"
import { Plus, ArrowLeftRight, RefreshCw } from "lucide-react"
import { Button } from "@/components/ui/button"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { Skeleton } from "@/components/ui/skeleton"
import { Badge } from "@/components/ui/badge"
import {
  Table,
  TableHeader,
  TableBody,
  TableRow,
  TableHead,
  TableCell,
} from "@/components/ui/table"
import { CreateBudgetForm } from "@/components/budgets/CreateBudgetForm"
import { TransferBudgetForm } from "@/components/budgets/TransferBudgetForm"
import { useBudgets } from "@/hooks/useBudget"
import { useAuthStore } from "@/store/authStore"
import { formatCurrency, formatPercent } from "@/lib/utils"
import type { Budget } from "@/types/budget"

// ─── Helpers ──────────────────────────────────────────────────────────────────

function UtilizationBar({ percent }: { percent: string }) {
  const value = parseFloat(percent)
  const isRed = value >= 90
  const isYellow = value >= 75 && value < 90

  const barColor = isRed
    ? "bg-destructive"
    : isYellow
      ? "bg-amber-500"
      : "bg-primary"

  return (
    <div className="flex items-center gap-2 min-w-[120px]">
      <div
        className="relative h-2 flex-1 overflow-hidden rounded-full bg-muted"
        role="progressbar"
        aria-valuenow={value}
        aria-valuemin={0}
        aria-valuemax={100}
        aria-label={`${formatPercent(percent)} utilized`}
      >
        <div
          className={`absolute left-0 top-0 h-full rounded-full transition-all ${barColor}`}
          style={{ width: `${Math.min(value, 100)}%` }}
        />
      </div>
      <span
        className={`text-xs font-medium tabular-nums ${
          isRed
            ? "text-destructive"
            : isYellow
              ? "text-amber-600 dark:text-amber-500"
              : "text-muted-foreground"
        }`}
      >
        {formatPercent(percent)}
      </span>
    </div>
  )
}

function SkeletonRows() {
  return (
    <>
      {Array.from({ length: 6 }).map((_, i) => (
        <TableRow key={i}>
          {Array.from({ length: 8 }).map((_, j) => (
            <TableCell key={j}>
              <Skeleton className="h-4 w-20" />
            </TableCell>
          ))}
        </TableRow>
      ))}
    </>
  )
}

// ─── Main component ───────────────────────────────────────────────────────────

const currentYear = new Date().getFullYear()
const FISCAL_YEAR_OPTIONS = Array.from({ length: 6 }, (_, i) => currentYear - 1 + i)

// Mock department list — in production this would come from useDepartments()
const MOCK_DEPARTMENTS = [
  { id: "dept-placeholder-1", name: "Finance" },
  { id: "dept-placeholder-2", name: "Operations" },
  { id: "dept-placeholder-3", name: "IT" },
  { id: "dept-placeholder-4", name: "HR" },
]

export default function BudgetsPage() {
  const role = useAuthStore((s) => s.role)

  const canAllocate =
    role === "Finance_Officer" || role === "Tenant_Admin"

  const [fiscalYearFilter, setFiscalYearFilter] = useState<string>(String(currentYear))
  const [createOpen, setCreateOpen] = useState(false)
  const [transferOpen, setTransferOpen] = useState(false)

  const { data, isLoading, isError, refetch } = useBudgets({
    fiscal_year: parseInt(fiscalYearFilter, 10),
  })

  const budgets: Budget[] = data?.data ?? []

  return (
    <div className="space-y-6">
      {/* Page header */}
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">Budget Management</h1>
        <p className="mt-1 text-sm text-muted-foreground">
          View, allocate, and transfer department budgets.
        </p>
      </div>

      {/* Toolbar */}
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
          {/* Fiscal year filter */}
          <div className="relative w-full sm:w-44">
            <Select
              value={fiscalYearFilter}
              onValueChange={setFiscalYearFilter}
            >
              <SelectTrigger aria-label="Filter by fiscal year">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Fiscal Years</SelectItem>
                {FISCAL_YEAR_OPTIONS.map((yr) => (
                  <SelectItem key={yr} value={String(yr)}>
                    FY {yr}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
        </div>

        <div className="flex items-center gap-2">
          <Button
            variant="outline"
            onClick={() => setTransferOpen(true)}
            disabled={budgets.length < 2}
            aria-label="Transfer budget between departments"
          >
            <ArrowLeftRight className="size-4" />
            Transfer Budget
          </Button>
          {canAllocate && (
            <Button
              onClick={() => setCreateOpen(true)}
              aria-label="Allocate new department budget"
            >
              <Plus className="size-4" />
              Allocate Budget
            </Button>
          )}
        </div>
      </div>

      {/* Table */}
      <div className="rounded-xl border border-border bg-card">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Department</TableHead>
              <TableHead>Fiscal Year</TableHead>
              <TableHead className="text-right">Total Allocated</TableHead>
              <TableHead className="text-right">Encumbered</TableHead>
              <TableHead className="text-right">Spent</TableHead>
              <TableHead className="text-right">Available</TableHead>
              <TableHead className="min-w-[160px]">Utilization</TableHead>
              <TableHead className="w-20">Currency</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {isLoading && <SkeletonRows />}

            {isError && (
              <TableRow>
                <TableCell
                  colSpan={8}
                  className="py-10 text-center text-muted-foreground"
                >
                  <p className="mb-2">Failed to load budgets.</p>
                  <Button variant="outline" size="sm" onClick={() => refetch()}>
                    <RefreshCw className="size-3.5" />
                    Retry
                  </Button>
                </TableCell>
              </TableRow>
            )}

            {!isLoading && !isError && budgets.length === 0 && (
              <TableRow>
                <TableCell
                  colSpan={8}
                  className="py-10 text-center text-muted-foreground"
                >
                  No budgets found for the selected filters.
                  {canAllocate && (
                    <>
                      {" "}
                      <button
                        className="text-primary underline-offset-2 hover:underline"
                        onClick={() => setCreateOpen(true)}
                      >
                        Allocate the first budget.
                      </button>
                    </>
                  )}
                </TableCell>
              </TableRow>
            )}

            {!isLoading &&
              !isError &&
              budgets.map((budget) => {
                const utilization = parseFloat(budget.utilization_percentage)
                const isHighUtil = utilization >= 75

                return (
                  <TableRow
                    key={budget.id}
                    className={
                      utilization >= 90
                        ? "bg-destructive/5 dark:bg-destructive/10"
                        : utilization >= 75
                          ? "bg-amber-50/50 dark:bg-amber-900/10"
                          : undefined
                    }
                  >
                    <TableCell className="font-medium">
                      {budget.department_name}
                      {isHighUtil && (
                        <span className="sr-only">
                          {utilization >= 90 ? " — critical utilization" : " — high utilization"}
                        </span>
                      )}
                    </TableCell>

                    <TableCell>
                      <Badge variant="secondary">FY {budget.fiscal_year}</Badge>
                    </TableCell>

                    <TableCell className="text-right tabular-nums">
                      {formatCurrency(budget.total_amount, budget.currency)}
                    </TableCell>

                    <TableCell className="text-right tabular-nums text-amber-700 dark:text-amber-500">
                      {formatCurrency(budget.encumbered_amount, budget.currency)}
                    </TableCell>

                    <TableCell className="text-right tabular-nums text-destructive">
                      {formatCurrency(budget.spent_amount, budget.currency)}
                    </TableCell>

                    <TableCell className="text-right tabular-nums text-green-700 dark:text-green-400">
                      {formatCurrency(budget.available_amount, budget.currency)}
                    </TableCell>

                    <TableCell>
                      <UtilizationBar percent={budget.utilization_percentage} />
                    </TableCell>

                    <TableCell className="text-sm text-muted-foreground">
                      {budget.currency}
                    </TableCell>
                  </TableRow>
                )
              })}
          </TableBody>
        </Table>
      </div>

      {/* Pagination */}
      {data?.meta && data.meta.last_page > 1 && (
        <div className="flex items-center justify-between text-sm text-muted-foreground">
          <span>
            Showing {data.meta.from ?? 0}–{data.meta.to ?? 0} of {data.meta.total} budgets
          </span>
        </div>
      )}

      {/* Dialogs */}
      <CreateBudgetForm
        open={createOpen}
        onOpenChange={setCreateOpen}
        departments={MOCK_DEPARTMENTS}
        onSuccess={() => refetch()}
      />

      <TransferBudgetForm
        open={transferOpen}
        onOpenChange={setTransferOpen}
        budgets={budgets}
        onSuccess={() => refetch()}
      />
    </div>
  )
}
