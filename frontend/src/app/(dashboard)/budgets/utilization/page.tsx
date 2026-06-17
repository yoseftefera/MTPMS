"use client"

/**
 * Budget Utilization Dashboard page.
 *
 * Accessible at /budgets/utilization.
 *
 * Features:
 * - Summary cards: total allocated, encumbered, spent, available
 * - Recharts BarChart (animated with Framer Motion wrapper) showing per-dept
 *   utilization with four series: Total (blue), Encumbered (yellow),
 *   Spent (red), Available (green)
 * - Fiscal year filter at top
 * - Departments at ≥75% utilization highlighted in yellow, ≥90% in red
 *
 * WCAG: aria-label on chart container, chart title via aria.
 *
 * Validates: Requirements 13.1, 13.10, 22.5, 22.10
 */

import { useState } from "react"
import { motion } from "framer-motion"
import {
  BarChart,
  Bar,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  Legend,
  ResponsiveContainer,
} from "recharts"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
} from "@/components/ui/card"
import { Skeleton } from "@/components/ui/skeleton"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { RefreshCw } from "lucide-react"
import { useUtilizationReport } from "@/hooks/useBudget"
import { formatCurrency, formatPercent } from "@/lib/utils"
import type { UtilizationReportItem } from "@/types/budget"

// ─── Constants ────────────────────────────────────────────────────────────────

const currentYear = new Date().getFullYear()
const FISCAL_YEAR_OPTIONS = Array.from({ length: 6 }, (_, i) => currentYear - 1 + i)

const CHART_COLORS = {
  total: "#3b82f6",       // blue-500
  encumbered: "#f59e0b",  // amber-500
  spent: "#ef4444",       // red-500
  available: "#22c55e",   // green-500
}

// ─── Custom tooltip ───────────────────────────────────────────────────────────

interface TooltipPayloadEntry {
  name: string
  value: number
  color: string
  dataKey: string
}

interface CustomTooltipProps {
  active?: boolean
  payload?: TooltipPayloadEntry[]
  label?: string
}

function CustomTooltip({ active, payload, label }: CustomTooltipProps) {
  if (!active || !payload?.length) return null

  return (
    <div
      role="tooltip"
      className="rounded-lg border border-border bg-card px-3 py-2 shadow-md text-xs"
    >
      <p className="mb-1 font-semibold text-sm">{label}</p>
      {payload.map((entry) => (
        <div key={entry.dataKey} className="flex items-center gap-2 py-0.5">
          <span
            className="size-2.5 rounded-sm flex-shrink-0"
            style={{ background: entry.color }}
          />
          <span className="text-muted-foreground capitalize">{entry.name}:</span>
          <span className="font-medium tabular-nums">
            {new Intl.NumberFormat("en-US", {
              notation: "compact",
              maximumFractionDigits: 1,
            }).format(entry.value)}
          </span>
        </div>
      ))}
    </div>
  )
}

// ─── Summary card ─────────────────────────────────────────────────────────────

interface SummaryCardProps {
  title: string
  value: string
  currency: string
  colorClass: string
}

function SummaryCard({ title, value, currency, colorClass }: SummaryCardProps) {
  return (
    <Card>
      <CardHeader className="pb-2">
        <CardTitle className="text-sm font-medium text-muted-foreground">
          {title}
        </CardTitle>
      </CardHeader>
      <CardContent>
        <p className={`text-xl font-bold tabular-nums ${colorClass}`}>
          {formatCurrency(value, currency)}
        </p>
      </CardContent>
    </Card>
  )
}

// ─── Utilization status legend ────────────────────────────────────────────────

function UtilizationLegend() {
  return (
    <div className="flex flex-wrap items-center gap-4 text-xs text-muted-foreground">
      <span className="flex items-center gap-1.5">
        <span className="size-2.5 rounded-sm bg-green-500" aria-hidden />
        Normal (&lt;75%)
      </span>
      <span className="flex items-center gap-1.5">
        <span className="size-2.5 rounded-sm bg-amber-500" aria-hidden />
        High (75–90%)
      </span>
      <span className="flex items-center gap-1.5">
        <span className="size-2.5 rounded-sm bg-destructive" aria-hidden />
        Critical (≥90%)
      </span>
    </div>
  )
}

// ─── Department utilization badge ─────────────────────────────────────────────

function UtilizationBadge({ item }: { item: UtilizationReportItem }) {
  const pct = parseFloat(item.utilization_percentage)
  if (pct >= 90)
    return (
      <Badge variant="destructive" aria-label={`${item.department_name} — critical utilization at ${formatPercent(item.utilization_percentage)}`}>
        {formatPercent(item.utilization_percentage)} — Critical
      </Badge>
    )
  if (pct >= 75)
    return (
      <Badge variant="warning" aria-label={`${item.department_name} — high utilization at ${formatPercent(item.utilization_percentage)}`}>
        {formatPercent(item.utilization_percentage)} — High
      </Badge>
    )
  return null
}

// ─── Skeleton ─────────────────────────────────────────────────────────────────

function ChartSkeleton() {
  return (
    <div className="space-y-3">
      <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
        {Array.from({ length: 4 }).map((_, i) => (
          <Card key={i}>
            <CardHeader className="pb-2">
              <Skeleton className="h-3 w-24" />
            </CardHeader>
            <CardContent>
              <Skeleton className="h-6 w-28" />
            </CardContent>
          </Card>
        ))}
      </div>
      <Card>
        <CardContent className="pt-6">
          <Skeleton className="h-72 w-full" />
        </CardContent>
      </Card>
    </div>
  )
}

// ─── Main component ───────────────────────────────────────────────────────────

export default function BudgetUtilizationPage() {
  const [fiscalYear, setFiscalYear] = useState<number>(currentYear)

  const { data, isLoading, isError, refetch } = useUtilizationReport(fiscalYear)

  const report = data?.data

  // Map to recharts-friendly format
  const chartData = (report?.items ?? []).map((item) => ({
    department: item.department_name,
    Total: parseFloat(item.total_amount),
    Encumbered: parseFloat(item.encumbered_amount),
    Spent: parseFloat(item.spent_amount),
    Available: parseFloat(item.available_amount),
    utilization: parseFloat(item.utilization_percentage),
  }))

  const currency = report?.items?.[0]?.currency ?? "USD"

  // Departments at ≥75% utilization
  const highUtilizationItems = (report?.items ?? []).filter(
    (item) => parseFloat(item.utilization_percentage) >= 75,
  )

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight">Budget Utilization</h1>
          <p className="mt-1 text-sm text-muted-foreground">
            Real-time consumption across all departments.
          </p>
        </div>

        <div className="flex items-center gap-2">
          <div className="relative w-40">
            <Select
              value={String(fiscalYear)}
              onValueChange={(val) => setFiscalYear(parseInt(val, 10))}
            >
              <SelectTrigger aria-label="Select fiscal year for utilization report">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {FISCAL_YEAR_OPTIONS.map((yr) => (
                  <SelectItem key={yr} value={String(yr)}>
                    FY {yr}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          <Button
            variant="outline"
            size="sm"
            onClick={() => refetch()}
            aria-label="Refresh utilization report"
          >
            <RefreshCw className="size-4" />
          </Button>
        </div>
      </div>

      {isError && (
        <div
          role="alert"
          className="rounded-xl border border-destructive/30 bg-destructive/5 px-4 py-3 text-sm text-destructive"
        >
          Failed to load utilization report.{" "}
          <button
            className="underline underline-offset-2"
            onClick={() => refetch()}
          >
            Try again
          </button>
        </div>
      )}

      {isLoading && <ChartSkeleton />}

      {!isLoading && !isError && report && (
        <>
          {/* Summary cards */}
          <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
            <SummaryCard
              title="Total Allocated"
              value={report.summary.total_allocated}
              currency={currency}
              colorClass="text-blue-600 dark:text-blue-400"
            />
            <SummaryCard
              title="Total Encumbered"
              value={report.summary.total_encumbered}
              currency={currency}
              colorClass="text-amber-600 dark:text-amber-400"
            />
            <SummaryCard
              title="Total Spent"
              value={report.summary.total_spent}
              currency={currency}
              colorClass="text-destructive"
            />
            <SummaryCard
              title="Total Available"
              value={report.summary.total_available}
              currency={currency}
              colorClass="text-green-600 dark:text-green-400"
            />
          </div>

          {/* High utilization alerts */}
          {highUtilizationItems.length > 0 && (
            <div
              role="region"
              aria-label="High utilization departments"
              className="flex flex-wrap gap-2"
            >
              {highUtilizationItems.map((item) => (
                <UtilizationBadge key={item.department_id} item={item} />
              ))}
            </div>
          )}

          {/* Bar chart */}
          <motion.div
            initial={{ opacity: 0, y: 16 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.4, ease: "easeOut" }}
          >
            <Card>
              <CardHeader>
                <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                  <CardTitle>
                    Department Budget Breakdown — FY {fiscalYear}
                  </CardTitle>
                  <UtilizationLegend />
                </div>
              </CardHeader>
              <CardContent>
                <div
                  role="img"
                  aria-label={`Bar chart showing budget utilization per department for fiscal year ${fiscalYear}. Four series: Total allocation in blue, Encumbered in yellow, Spent in red, and Available in green.`}
                >
                  {chartData.length === 0 ? (
                    <p className="py-16 text-center text-sm text-muted-foreground">
                      No budget data for FY {fiscalYear}.
                    </p>
                  ) : (
                    <ResponsiveContainer width="100%" height={360}>
                      <BarChart
                        data={chartData}
                        margin={{ top: 8, right: 16, left: 8, bottom: 8 }}
                        barGap={2}
                        barCategoryGap="30%"
                      >
                        <CartesianGrid strokeDasharray="3 3" className="stroke-border" />
                        <XAxis
                          dataKey="department"
                          tick={{ fontSize: 11 }}
                          tickLine={false}
                          axisLine={false}
                        />
                        <YAxis
                          tickFormatter={(v: number) =>
                            new Intl.NumberFormat("en-US", {
                              notation: "compact",
                              maximumFractionDigits: 1,
                            }).format(v)
                          }
                          tick={{ fontSize: 11 }}
                          tickLine={false}
                          axisLine={false}
                        />
                        <Tooltip content={<CustomTooltip />} />
                        <Legend
                          wrapperStyle={{ fontSize: "12px" }}
                          iconSize={10}
                          iconType="square"
                        />
                        <Bar
                          dataKey="Total"
                          fill={CHART_COLORS.total}
                          radius={[3, 3, 0, 0]}
                          isAnimationActive
                          animationDuration={800}
                          animationEasing="ease-out"
                        />
                        <Bar
                          dataKey="Encumbered"
                          fill={CHART_COLORS.encumbered}
                          radius={[3, 3, 0, 0]}
                          isAnimationActive
                          animationDuration={800}
                          animationEasing="ease-out"
                        />
                        <Bar
                          dataKey="Spent"
                          fill={CHART_COLORS.spent}
                          radius={[3, 3, 0, 0]}
                          isAnimationActive
                          animationDuration={800}
                          animationEasing="ease-out"
                        />
                        <Bar
                          dataKey="Available"
                          fill={CHART_COLORS.available}
                          radius={[3, 3, 0, 0]}
                          isAnimationActive
                          animationDuration={800}
                          animationEasing="ease-out"
                        />
                      </BarChart>
                    </ResponsiveContainer>
                  )}
                </div>
              </CardContent>
            </Card>
          </motion.div>

          {/* Department detail list */}
          {chartData.length > 0 && (
            <Card>
              <CardHeader>
                <CardTitle className="text-sm font-medium">Department Detail</CardTitle>
              </CardHeader>
              <CardContent className="pt-0">
                <div className="divide-y divide-border">
                  {report.items.map((item) => {
                    const pct = parseFloat(item.utilization_percentage)
                    const isCritical = pct >= 90
                    const isHigh = pct >= 75 && pct < 90

                    return (
                      <div
                        key={item.department_id}
                        className={`flex flex-col gap-1 py-3 sm:flex-row sm:items-center sm:justify-between ${
                          isCritical
                            ? "text-destructive"
                            : isHigh
                              ? "text-amber-700 dark:text-amber-500"
                              : ""
                        }`}
                      >
                        <div>
                          <p className="font-medium text-foreground">{item.department_name}</p>
                          <p className="text-xs text-muted-foreground">
                            {formatPercent(item.utilization_percentage)} utilized
                          </p>
                        </div>
                        <div className="flex flex-wrap gap-x-6 gap-y-1 text-xs tabular-nums">
                          <span>
                            <span className="text-muted-foreground">Total: </span>
                            <span className="font-medium text-foreground">
                              {formatCurrency(item.total_amount, item.currency)}
                            </span>
                          </span>
                          <span>
                            <span className="text-muted-foreground">Spent: </span>
                            <span className="font-medium text-destructive">
                              {formatCurrency(item.spent_amount, item.currency)}
                            </span>
                          </span>
                          <span>
                            <span className="text-muted-foreground">Available: </span>
                            <span className="font-medium text-green-700 dark:text-green-400">
                              {formatCurrency(item.available_amount, item.currency)}
                            </span>
                          </span>
                        </div>
                      </div>
                    )
                  })}
                </div>
              </CardContent>
            </Card>
          )}
        </>
      )}
    </div>
  )
}
