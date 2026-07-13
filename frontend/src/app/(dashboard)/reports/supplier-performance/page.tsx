"use client";

/**
 * Supplier Performance Report page.
 *
 * Shows per-supplier metrics: on-time delivery rate, quality acceptance rate,
 * total PO value, PO count, and average bid competitiveness.
 *
 * Filters: date range, supplier
 * Exports: PDF / Excel
 *
 * Validates: Requirements 16.4, 16.10, 22.1, 22.10
 */

import { useState } from "react";
import {
  RadarChart,
  Radar,
  PolarGrid,
  PolarAngleAxis,
  BarChart,
  Bar,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  ResponsiveContainer,
  Cell,
} from "recharts";
import { RefreshCw } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { Badge } from "@/components/ui/badge";
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
import { useSupplierPerformance } from "@/hooks/useReporting";
import { formatCurrency, formatPercent } from "@/lib/utils";
import type { ReportFilters } from "@/types/reporting";

// ─── Helpers ──────────────────────────────────────────────────────────────────

function rateColor(rate: number) {
  if (rate >= 90) return "bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-400";
  if (rate >= 70) return "bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-400";
  return "bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400";
}

// ─── Page ─────────────────────────────────────────────────────────────────────

export default function SupplierPerformancePage() {
  const [filters, setFilters] = useState<ReportFilters>({});

  const { data, isLoading, isError, refetch } = useSupplierPerformance({
    date_from: filters.date_from,
    date_to: filters.date_to,
    supplier_id: filters.supplier_id,
  });

  const suppliers = data?.data?.suppliers ?? [];

  // Chart data: top 8 by on-time delivery
  const barData = suppliers.slice(0, 8).map((s) => ({
    name: s.supplier_name.length > 14 ? s.supplier_name.slice(0, 12) + "…" : s.supplier_name,
    fullName: s.supplier_name,
    onTime: parseFloat(s.on_time_delivery_rate),
    quality: parseFloat(s.quality_acceptance_rate),
  }));

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight">Supplier Performance</h1>
          <p className="mt-1 text-sm text-muted-foreground">
            On-time delivery, quality acceptance, and bid competitiveness metrics.
          </p>
        </div>
        <ExportButtons
          reportType="supplier-performance"
          filters={filters}
          filename="supplier-performance"
        />
      </div>

      {/* Filters */}
      <ReportFiltersBar
        filters={filters}
        onChange={setFilters}
        showDateRange
        showSupplier
      />

      {isError && (
        <div className="rounded-xl border border-destructive/30 bg-destructive/5 p-6 text-center">
          <p className="text-sm text-muted-foreground mb-3">Failed to load report.</p>
          <Button variant="outline" size="sm" onClick={() => refetch()}>
            <RefreshCw className="size-3.5" /> Retry
          </Button>
        </div>
      )}

      {/* Delivery & Quality Bar Chart */}
      <div className="rounded-xl border bg-card p-5">
        <h2 className="text-base font-semibold">Delivery & Quality Rates (Top 8 Suppliers)</h2>
        <p className="mt-0.5 mb-4 text-xs text-muted-foreground">
          On-time delivery rate vs. quality acceptance rate (%)
        </p>
        {isLoading ? (
          <Skeleton className="h-64 w-full rounded-lg" />
        ) : (
          <AnimatedChart>
            <ResponsiveContainer width="100%" height={260}>
              <BarChart data={barData} margin={{ top: 4, right: 16, bottom: 4, left: 0 }}>
                <CartesianGrid strokeDasharray="3 3" vertical={false} className="stroke-border" />
                <XAxis dataKey="name" tick={{ fontSize: 11 }} tickLine={false} axisLine={false} />
                <YAxis
                  domain={[0, 100]}
                  tick={{ fontSize: 11 }}
                  tickLine={false}
                  axisLine={false}
                  width={32}
                  tickFormatter={(v) => `${v}%`}
                />
                <Tooltip
                  contentStyle={{
                    borderRadius: 8,
                    border: "1px solid hsl(var(--border))",
                    background: "hsl(var(--card))",
                    fontSize: 12,
                  }}
                  formatter={(v: number, name: string) => [
                    `${v.toFixed(1)}%`,
                    name === "onTime" ? "On-Time Delivery" : "Quality Acceptance",
                  ]}
                  labelFormatter={(_label, payload) =>
                    payload?.[0]?.payload?.fullName ?? _label
                  }
                />
                <Bar dataKey="onTime" name="onTime" fill="#6366f1" radius={[4, 4, 0, 0]} maxBarSize={24}>
                  {barData.map((entry, i) => (
                    <Cell
                      key={i}
                      fill={entry.onTime >= 90 ? "#22c55e" : entry.onTime >= 70 ? "#f59e0b" : "#ef4444"}
                    />
                  ))}
                </Bar>
                <Bar dataKey="quality" name="quality" fill="#94a3b8" radius={[4, 4, 0, 0]} maxBarSize={24} />
              </BarChart>
            </ResponsiveContainer>
          </AnimatedChart>
        )}
      </div>

      {/* Data table */}
      <div className="rounded-xl border bg-card overflow-hidden">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Supplier</TableHead>
              <TableHead className="text-right">On-Time Delivery</TableHead>
              <TableHead className="text-right">Quality Acceptance</TableHead>
              <TableHead className="text-right">Total PO Value</TableHead>
              <TableHead className="text-right">PO Count</TableHead>
              <TableHead className="text-right">Avg. Bid Score</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {isLoading
              ? Array.from({ length: 6 }).map((_, i) => (
                <TableRow key={i}>
                  {Array.from({ length: 6 }).map((_, j) => (
                    <TableCell key={j}><Skeleton className="h-4 w-16" /></TableCell>
                  ))}
                </TableRow>
              ))
              : suppliers.length === 0 ? (
                <TableRow>
                  <TableCell colSpan={6} className="py-10 text-center text-muted-foreground">
                    No supplier data for the selected filters.
                  </TableCell>
                </TableRow>
              ) : suppliers.map((s) => {
                const onTime = parseFloat(s.on_time_delivery_rate);
                const quality = parseFloat(s.quality_acceptance_rate);
                return (
                  <TableRow key={s.supplier_id}>
                    <TableCell className="font-medium">{s.supplier_name}</TableCell>
                    <TableCell className="text-right">
                      <Badge className={rateColor(onTime)}>
                        {formatPercent(s.on_time_delivery_rate)}
                      </Badge>
                    </TableCell>
                    <TableCell className="text-right">
                      <Badge className={rateColor(quality)}>
                        {formatPercent(s.quality_acceptance_rate)}
                      </Badge>
                    </TableCell>
                    <TableCell className="text-right tabular-nums">
                      {formatCurrency(s.total_po_value)}
                    </TableCell>
                    <TableCell className="text-right tabular-nums">
                      {s.po_count}
                    </TableCell>
                    <TableCell className="text-right tabular-nums text-muted-foreground">
                      {parseFloat(s.avg_bid_competitiveness).toFixed(1)}
                    </TableCell>
                  </TableRow>
                );
              })}
          </TableBody>
        </Table>
      </div>
    </div>
  );
}
