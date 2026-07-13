"use client";

/**
 * Spending Analytics Report page.
 *
 * Shows expenditure breakdown by department, category, and supplier,
 * with a month-over-month trend line chart.
 *
 * Filters: date range, department, category, supplier
 * Exports: PDF / Excel
 *
 * Validates: Requirements 16.3, 16.10, 22.1, 22.10
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
} from "recharts";
import { RefreshCw } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
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
import { useSpendingAnalytics } from "@/hooks/useReporting";
import { formatCurrency, formatPercent } from "@/lib/utils";
import type { ReportFilters } from "@/types/reporting";

// ─── Helpers ──────────────────────────────────────────────────────────────────

function ChartSkeleton({ height = 280 }: { height?: number }) {
  return <Skeleton style={{ height }} className="w-full rounded-xl" />;
}

// ─── Page ─────────────────────────────────────────────────────────────────────

export default function SpendingAnalyticsPage() {
  const [filters, setFilters] = useState<ReportFilters>({});

  const { data, isLoading, isError, refetch } = useSpendingAnalytics(filters);
  const report = data?.data;

  const monthlyData = (report?.monthly_trend ?? []).map((d) => ({
    month: d.month,
    current: parseFloat(d.amount),
    previous: parseFloat(d.previous_amount),
  }));

  const byDept = (report?.by_department ?? []).map((d) => ({
    label: d.label,
    amount: parseFloat(d.amount),
    percentage: parseFloat(d.percentage),
  }));

  const byCategory = (report?.by_category ?? []).map((d) => ({
    label: d.label,
    amount: parseFloat(d.amount),
    percentage: parseFloat(d.percentage),
  }));

  const currency = report?.currency ?? "USD";

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight">Spending Analytics</h1>
          <p className="mt-1 text-sm text-muted-foreground">
            Expenditure breakdown by department, category, and supplier.
          </p>
        </div>
        <ExportButtons
          reportType="spending-analytics"
          filters={filters}
          filename="spending-analytics"
        />
      </div>

      {/* Filters */}
      <ReportFiltersBar
        filters={filters}
        onChange={setFilters}
        showDateRange
        showDepartment
        showCategory
        showSupplier
      />

      {/* KPI summary */}
      {report && (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
          <div className="rounded-xl border bg-card p-4">
            <p className="text-xs text-muted-foreground">Total Spend</p>
            <p className="mt-1 text-2xl font-semibold tabular-nums">
              {formatCurrency(report.total_spend, currency)}
            </p>
          </div>
        </div>
      )}

      {isError && (
        <div className="rounded-xl border border-destructive/30 bg-destructive/5 p-6 text-center">
          <p className="text-sm text-muted-foreground mb-3">
            Failed to load report.
          </p>
          <Button variant="outline" size="sm" onClick={() => refetch()}>
            <RefreshCw className="size-3.5" />
            Retry
          </Button>
        </div>
      )}

      {/* Monthly Trend Chart */}
      <div className="rounded-xl border bg-card p-5">
        <h2 className="text-base font-semibold">Monthly Spend Trend</h2>
        <p className="mt-0.5 mb-4 text-xs text-muted-foreground">
          Current vs. prior period
        </p>
        {isLoading ? (
          <ChartSkeleton />
        ) : (
          <AnimatedChart>
            <ResponsiveContainer width="100%" height={280}>
              <LineChart data={monthlyData} margin={{ top: 4, right: 16, bottom: 4, left: 0 }}>
                <CartesianGrid strokeDasharray="3 3" vertical={false} className="stroke-border" />
                <XAxis dataKey="month" tick={{ fontSize: 11 }} tickLine={false} axisLine={false} />
                <YAxis
                  tick={{ fontSize: 11 }}
                  tickLine={false}
                  axisLine={false}
                  width={64}
                  tickFormatter={(v) =>
                    new Intl.NumberFormat("en-US", {
                      style: "currency",
                      currency,
                      notation: "compact",
                      maximumFractionDigits: 1,
                    }).format(v)
                  }
                />
                <Tooltip
                  contentStyle={{
                    borderRadius: 8,
                    border: "1px solid hsl(var(--border))",
                    background: "hsl(var(--card))",
                    fontSize: 12,
                  }}
                  formatter={(value: number, name: string) => [
                    formatCurrency(value, currency),
                    name === "current" ? "This Period" : "Prior Period",
                  ]}
                />
                <Legend
                  formatter={(v) => (v === "current" ? "This Period" : "Prior Period")}
                  wrapperStyle={{ fontSize: 12, paddingTop: 8 }}
                />
                <Line
                  type="monotone"
                  dataKey="current"
                  stroke="#6366f1"
                  strokeWidth={2}
                  dot={{ r: 3 }}
                  activeDot={{ r: 5 }}
                />
                <Line
                  type="monotone"
                  dataKey="previous"
                  stroke="#94a3b8"
                  strokeWidth={2}
                  strokeDasharray="4 2"
                  dot={{ r: 3 }}
                />
              </LineChart>
            </ResponsiveContainer>
          </AnimatedChart>
        )}
      </div>

      {/* Breakdown tabs */}
      <Tabs defaultValue="department">
        <TabsList>
          <TabsTrigger value="department">By Department</TabsTrigger>
          <TabsTrigger value="category">By Category</TabsTrigger>
          <TabsTrigger value="supplier">By Supplier</TabsTrigger>
        </TabsList>

        {/* Department */}
        <TabsContent value="department" className="mt-4 space-y-4">
          {isLoading ? (
            <ChartSkeleton height={240} />
          ) : (
            <AnimatedChart>
              <div className="rounded-xl border bg-card p-5">
                <ResponsiveContainer width="100%" height={240}>
                  <BarChart
                    data={byDept.slice(0, 10)}
                    layout="vertical"
                    margin={{ top: 4, right: 16, bottom: 4, left: 16 }}
                  >
                    <CartesianGrid strokeDasharray="3 3" horizontal={false} className="stroke-border" />
                    <XAxis
                      type="number"
                      tick={{ fontSize: 11 }}
                      tickLine={false}
                      axisLine={false}
                      tickFormatter={(v) =>
                        new Intl.NumberFormat("en-US", {
                          style: "currency",
                          currency,
                          notation: "compact",
                          maximumFractionDigits: 1,
                        }).format(v)
                      }
                    />
                    <YAxis
                      type="category"
                      dataKey="label"
                      tick={{ fontSize: 11 }}
                      tickLine={false}
                      axisLine={false}
                      width={90}
                    />
                    <Tooltip
                      contentStyle={{
                        borderRadius: 8,
                        border: "1px solid hsl(var(--border))",
                        background: "hsl(var(--card))",
                        fontSize: 12,
                      }}
                      formatter={(v: number) => [formatCurrency(v, currency), "Spend"]}
                    />
                    <Bar dataKey="amount" fill="#6366f1" radius={[0, 4, 4, 0]} maxBarSize={20} />
                  </BarChart>
                </ResponsiveContainer>
              </div>
            </AnimatedChart>
          )}

          {/* Table */}
          <div className="rounded-xl border bg-card overflow-hidden">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Department</TableHead>
                  <TableHead className="text-right">Amount</TableHead>
                  <TableHead className="text-right">% of Total</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {isLoading
                  ? Array.from({ length: 5 }).map((_, i) => (
                    <TableRow key={i}>
                      <TableCell><Skeleton className="h-4 w-32" /></TableCell>
                      <TableCell><Skeleton className="h-4 w-20 ml-auto" /></TableCell>
                      <TableCell><Skeleton className="h-4 w-12 ml-auto" /></TableCell>
                    </TableRow>
                  ))
                  : report?.by_department.map((d) => (
                    <TableRow key={d.label}>
                      <TableCell className="font-medium">{d.label}</TableCell>
                      <TableCell className="text-right tabular-nums">
                        {formatCurrency(d.amount, currency)}
                      </TableCell>
                      <TableCell className="text-right tabular-nums text-muted-foreground">
                        {formatPercent(d.percentage)}
                      </TableCell>
                    </TableRow>
                  ))}
              </TableBody>
            </Table>
          </div>
        </TabsContent>

        {/* Category */}
        <TabsContent value="category" className="mt-4">
          <div className="rounded-xl border bg-card overflow-hidden">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Category</TableHead>
                  <TableHead className="text-right">Amount</TableHead>
                  <TableHead className="text-right">% of Total</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {isLoading
                  ? Array.from({ length: 5 }).map((_, i) => (
                    <TableRow key={i}>
                      <TableCell><Skeleton className="h-4 w-28" /></TableCell>
                      <TableCell><Skeleton className="h-4 w-20 ml-auto" /></TableCell>
                      <TableCell><Skeleton className="h-4 w-12 ml-auto" /></TableCell>
                    </TableRow>
                  ))
                  : report?.by_category.map((d) => (
                    <TableRow key={d.label}>
                      <TableCell className="font-medium">{d.label}</TableCell>
                      <TableCell className="text-right tabular-nums">
                        {formatCurrency(d.amount, currency)}
                      </TableCell>
                      <TableCell className="text-right tabular-nums text-muted-foreground">
                        {formatPercent(d.percentage)}
                      </TableCell>
                    </TableRow>
                  ))}
              </TableBody>
            </Table>
          </div>
        </TabsContent>

        {/* Supplier */}
        <TabsContent value="supplier" className="mt-4">
          <div className="rounded-xl border bg-card overflow-hidden">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Supplier</TableHead>
                  <TableHead className="text-right">Amount</TableHead>
                  <TableHead className="text-right">% of Total</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {isLoading
                  ? Array.from({ length: 5 }).map((_, i) => (
                    <TableRow key={i}>
                      <TableCell><Skeleton className="h-4 w-36" /></TableCell>
                      <TableCell><Skeleton className="h-4 w-20 ml-auto" /></TableCell>
                      <TableCell><Skeleton className="h-4 w-12 ml-auto" /></TableCell>
                    </TableRow>
                  ))
                  : report?.by_supplier.map((d) => (
                    <TableRow key={d.label}>
                      <TableCell className="font-medium">{d.label}</TableCell>
                      <TableCell className="text-right tabular-nums">
                        {formatCurrency(d.amount, currency)}
                      </TableCell>
                      <TableCell className="text-right tabular-nums text-muted-foreground">
                        {formatPercent(d.percentage)}
                      </TableCell>
                    </TableRow>
                  ))}
              </TableBody>
            </Table>
          </div>
        </TabsContent>
      </Tabs>
    </div>
  );
}
