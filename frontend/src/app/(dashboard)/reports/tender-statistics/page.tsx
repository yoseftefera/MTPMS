"use client";

/**
 * Tender Statistics Report page.
 *
 * Displays tender outcomes by status and category, average bids per tender,
 * evaluation time, and a monthly published/awarded/cancelled trend.
 *
 * Filters: date range, category, status
 * Exports: PDF / Excel
 *
 * Validates: Requirements 16.5, 16.10, 22.1, 22.10
 */

import { useState } from "react";
import {
  BarChart,
  Bar,
  LineChart,
  Line,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  ResponsiveContainer,
  Legend,
  PieChart,
  Pie,
  Cell,
} from "recharts";
import { RefreshCw } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { ReportFiltersBar } from "@/components/reports/ReportFilters";
import { ExportButtons } from "@/components/reports/ExportButtons";
import { AnimatedChart } from "@/components/reports/AnimatedChart";
import { useTenderStatistics } from "@/hooks/useReporting";
import { formatCurrency } from "@/lib/utils";
import type { ReportFilters } from "@/types/reporting";

// ─── Constants ────────────────────────────────────────────────────────────────

const TENDER_STATUS_OPTIONS = [
  { value: "draft", label: "Draft" },
  { value: "published", label: "Published" },
  { value: "closed", label: "Closed" },
  { value: "awarded", label: "Awarded" },
  { value: "cancelled", label: "Cancelled" },
];

const STATUS_COLORS: Record<string, string> = {
  draft: "#94a3b8",
  published: "#6366f1",
  closed: "#f59e0b",
  awarded: "#22c55e",
  cancelled: "#ef4444",
};

// ─── Page ─────────────────────────────────────────────────────────────────────

export default function TenderStatisticsPage() {
  const [filters, setFilters] = useState<ReportFilters>({});

  const { data, isLoading, isError, refetch } = useTenderStatistics({
    date_from: filters.date_from,
    date_to: filters.date_to,
    category: filters.category,
    status: filters.status,
  });

  const report = data?.data;

  // Pie chart — status breakdown
  const statusPieData = Object.entries(report?.by_status ?? {}).map(
    ([status, count]) => ({
      name: status.charAt(0).toUpperCase() + status.slice(1),
      value: count,
      fill: STATUS_COLORS[status] ?? "#6366f1",
    }),
  );

  // Monthly trend line
  const trendData = (report?.monthly_trend ?? []).map((d) => ({
    month: d.month,
    published: d.published,
    awarded: d.awarded,
    cancelled: d.cancelled,
  }));

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight">Tender Statistics</h1>
          <p className="mt-1 text-sm text-muted-foreground">
            Tender outcomes, bidding activity, and evaluation timelines.
          </p>
        </div>
        <ExportButtons
          reportType="tender-statistics"
          filters={filters}
          filename="tender-statistics"
        />
      </div>

      {/* Filters */}
      <ReportFiltersBar
        filters={filters}
        onChange={setFilters}
        showDateRange
        showCategory
        showStatus
        statusOptions={TENDER_STATUS_OPTIONS}
      />

      {isError && (
        <div className="rounded-xl border border-destructive/30 bg-destructive/5 p-6 text-center">
          <p className="text-sm text-muted-foreground mb-3">Failed to load report.</p>
          <Button variant="outline" size="sm" onClick={() => refetch()}>
            <RefreshCw className="size-3.5" /> Retry
          </Button>
        </div>
      )}

      {/* Summary KPIs */}
      {report && (
        <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
          <div className="rounded-xl border bg-card p-4">
            <p className="text-xs text-muted-foreground">Total Tenders</p>
            <p className="mt-1 text-2xl font-semibold">{report.total_tenders}</p>
          </div>
          <div className="rounded-xl border bg-card p-4">
            <p className="text-xs text-muted-foreground">Avg. Bids / Tender</p>
            <p className="mt-1 text-2xl font-semibold">
              {parseFloat(report.avg_bids_per_tender).toFixed(1)}
            </p>
          </div>
          <div className="rounded-xl border bg-card p-4">
            <p className="text-xs text-muted-foreground">Avg. Evaluation Days</p>
            <p className="mt-1 text-2xl font-semibold">
              {parseFloat(report.avg_evaluation_days).toFixed(1)}
            </p>
          </div>
          <div className="rounded-xl border bg-card p-4">
            <p className="text-xs text-muted-foreground">Awarded</p>
            <p className="mt-1 text-2xl font-semibold text-emerald-600 dark:text-emerald-400">
              {report.by_status.awarded ?? 0}
            </p>
          </div>
        </div>
      )}

      {/* Charts */}
      <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
        {/* Status Pie */}
        <div className="rounded-xl border bg-card p-5">
          <h2 className="text-base font-semibold">Status Distribution</h2>
          <p className="mt-0.5 mb-4 text-xs text-muted-foreground">Breakdown by current status</p>
          {isLoading ? (
            <Skeleton className="h-56 w-full rounded-lg" />
          ) : (
            <AnimatedChart>
              <ResponsiveContainer width="100%" height={224}>
                <PieChart>
                  <Pie
                    data={statusPieData}
                    cx="50%"
                    cy="50%"
                    outerRadius={88}
                    dataKey="value"
                    label={({ name, percent }) =>
                      `${name} ${(percent * 100).toFixed(0)}%`
                    }
                    labelLine={false}
                  >
                    {statusPieData.map((entry, i) => (
                      <Cell key={i} fill={entry.fill} />
                    ))}
                  </Pie>
                  <Tooltip
                    contentStyle={{
                      borderRadius: 8,
                      border: "1px solid hsl(var(--border))",
                      background: "hsl(var(--card))",
                      fontSize: 12,
                    }}
                  />
                </PieChart>
              </ResponsiveContainer>
            </AnimatedChart>
          )}
        </div>

        {/* Monthly Trend */}
        <div className="rounded-xl border bg-card p-5">
          <h2 className="text-base font-semibold">Monthly Tender Activity</h2>
          <p className="mt-0.5 mb-4 text-xs text-muted-foreground">
            Published, awarded, and cancelled per month
          </p>
          {isLoading ? (
            <Skeleton className="h-56 w-full rounded-lg" />
          ) : (
            <AnimatedChart delay={0.1}>
              <ResponsiveContainer width="100%" height={224}>
                <LineChart data={trendData} margin={{ top: 4, right: 16, bottom: 4, left: 0 }}>
                  <CartesianGrid strokeDasharray="3 3" vertical={false} className="stroke-border" />
                  <XAxis dataKey="month" tick={{ fontSize: 11 }} tickLine={false} axisLine={false} />
                  <YAxis allowDecimals={false} tick={{ fontSize: 11 }} tickLine={false} axisLine={false} width={28} />
                  <Tooltip
                    contentStyle={{
                      borderRadius: 8,
                      border: "1px solid hsl(var(--border))",
                      background: "hsl(var(--card))",
                      fontSize: 12,
                    }}
                  />
                  <Legend wrapperStyle={{ fontSize: 12, paddingTop: 8 }} />
                  <Line type="monotone" dataKey="published" stroke="#6366f1" strokeWidth={2} dot={{ r: 3 }} />
                  <Line type="monotone" dataKey="awarded" stroke="#22c55e" strokeWidth={2} dot={{ r: 3 }} />
                  <Line type="monotone" dataKey="cancelled" stroke="#ef4444" strokeWidth={2} strokeDasharray="4 2" dot={{ r: 3 }} />
                </LineChart>
              </ResponsiveContainer>
            </AnimatedChart>
          )}
        </div>
      </div>

      {/* Category breakdown table */}
      <div className="rounded-xl border bg-card overflow-hidden">
        <div className="px-5 py-3 border-b">
          <h2 className="text-sm font-semibold">By Category</h2>
        </div>
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Category</TableHead>
              <TableHead className="text-right">Count</TableHead>
              <TableHead className="text-right">Total Est. Value</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {isLoading
              ? Array.from({ length: 5 }).map((_, i) => (
                <TableRow key={i}>
                  <TableCell><Skeleton className="h-4 w-28" /></TableCell>
                  <TableCell><Skeleton className="h-4 w-8 ml-auto" /></TableCell>
                  <TableCell><Skeleton className="h-4 w-24 ml-auto" /></TableCell>
                </TableRow>
              ))
              : report?.by_category.length === 0 ? (
                <TableRow>
                  <TableCell colSpan={3} className="py-8 text-center text-muted-foreground">
                    No tender data for the selected filters.
                  </TableCell>
                </TableRow>
              ) : report?.by_category.map((c) => (
                <TableRow key={c.category}>
                  <TableCell className="font-medium">{c.category}</TableCell>
                  <TableCell className="text-right tabular-nums">{c.count}</TableCell>
                  <TableCell className="text-right tabular-nums">
                    {formatCurrency(c.total_estimated_value)}
                  </TableCell>
                </TableRow>
              ))}
          </TableBody>
        </Table>
      </div>
    </div>
  );
}
