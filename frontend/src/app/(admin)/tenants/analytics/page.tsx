"use client";

/**
 * Tenant Analytics Dashboard — System_Admin.
 *
 * Shows cross-tenant aggregated analytics without exposing individual tenant
 * data to other tenants. Includes:
 *   - KPI cards (total, active, suspended, new this month)
 *   - Bar chart — registrations per month
 *   - Pie chart — status distribution
 *   - Table — top tenants by activity
 *
 * Uses Recharts, Framer Motion animations, ChartSkeleton, and CardSkeleton.
 *
 * Routes: /admin/tenants/analytics
 *
 * Validates: Requirements 1.8, 22.5, 22.10
 */

import { SectionErrorBoundary } from "@/components/ui/SectionErrorBoundary";
import { CardSkeleton } from "@/components/ui/CardSkeleton";
import { ChartSkeleton } from "@/components/ui/ChartSkeleton";
import { KpiCard } from "@/components/reports/KpiCard";
import { AnimatedChart } from "@/components/reports/AnimatedChart";
import { useTenantAnalytics } from "@/hooks/useTenants";
import {
  BarChart,
  Bar,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  ResponsiveContainer,
  PieChart,
  Pie,
  Cell,
  Legend,
} from "recharts";
import {
  Building2,
  CheckCircle2,
  PauseCircle,
  TrendingUp,
  Activity,
  RefreshCw,
} from "lucide-react";
import { Button } from "@/components/ui/button";

// ─── Chart colours ────────────────────────────────────────────────────────────

const STATUS_COLORS: Record<string, string> = {
  active: "#22c55e",
  suspended: "#f59e0b",
  deactivated: "#ef4444",
};

const BAR_COLOR = "hsl(var(--primary))";

// ─── Helpers ──────────────────────────────────────────────────────────────────

function formatMonth(raw: string): string {
  try {
    const [year, month] = raw.split("-");
    return new Intl.DateTimeFormat("en-US", {
      month: "short",
      year: "2-digit",
    }).format(new Date(Number(year), Number(month) - 1));
  } catch {
    return raw;
  }
}

// ─── KPI skeleton row ─────────────────────────────────────────────────────────

function KpiSkeletonRow() {
  return (
    <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
      {Array.from({ length: 4 }).map((_, i) => (
        <CardSkeleton key={i} lines={2} />
      ))}
    </div>
  );
}

// ─── Main dashboard ────────────────────────────────────────────────────────────

function AnalyticsDashboardContent() {
  const { data, isLoading, isError, refetch } = useTenantAnalytics();

  // ── Loading ──────────────────────────────────────────────────────────────
  if (isLoading) {
    return (
      <div className="space-y-6">
        <KpiSkeletonRow />
        <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
          <ChartSkeleton height={260} title legend />
          <ChartSkeleton height={260} title legend />
        </div>
        <CardSkeleton lines={5} />
      </div>
    );
  }

  // ── Error ────────────────────────────────────────────────────────────────
  if (isError || !data?.data) {
    return (
      <div className="flex flex-col items-center gap-4 py-16 text-center text-muted-foreground">
        <p>Failed to load analytics data.</p>
        <Button variant="outline" size="sm" onClick={() => refetch()}>
          <RefreshCw className="size-4" />
          Retry
        </Button>
      </div>
    );
  }

  const analytics = data.data;

  // ── Prepare chart data ───────────────────────────────────────────────────
  const registrationsByMonth = analytics.registrations_by_month.map((d) => ({
    ...d,
    label: formatMonth(d.month),
  }));

  const statusDistribution = analytics.status_distribution.map((d) => ({
    name: d.status.charAt(0).toUpperCase() + d.status.slice(1),
    value: d.count,
    fill: STATUS_COLORS[d.status] ?? "#94a3b8",
  }));

  return (
    <div className="space-y-6">
      {/* KPI cards */}
      <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
        <KpiCard
          label="Total Tenants"
          value={analytics.total_tenants}
          icon={Building2}
          iconClassName="bg-primary/10 text-primary"
          delay={0}
        />
        <KpiCard
          label="Active Tenants"
          value={analytics.active_tenants}
          icon={CheckCircle2}
          iconClassName="bg-green-500/10 text-green-600 dark:text-green-400"
          delay={0.05}
        />
        <KpiCard
          label="Suspended"
          value={analytics.suspended_tenants}
          icon={PauseCircle}
          iconClassName="bg-amber-500/10 text-amber-600 dark:text-amber-400"
          alert={analytics.suspended_tenants > 0}
          delay={0.1}
        />
        <KpiCard
          label="New This Month"
          value={analytics.new_tenants_this_month}
          icon={TrendingUp}
          iconClassName="bg-blue-500/10 text-blue-600 dark:text-blue-400"
          delay={0.15}
        />
      </div>

      {/* Charts row */}
      <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
        {/* Registrations bar chart */}
        <SectionErrorBoundary title="Registrations chart">
          <AnimatedChart delay={0.2}>
            <div className="rounded-xl border border-border bg-card p-5 space-y-4">
              <div>
                <h2 className="text-sm font-semibold">
                  Tenant Registrations
                </h2>
                <p className="text-xs text-muted-foreground mt-0.5">
                  New tenants registered per month
                </p>
              </div>
              <ResponsiveContainer width="100%" height={260}>
                <BarChart
                  data={registrationsByMonth}
                  margin={{ top: 4, right: 12, bottom: 0, left: -12 }}
                >
                  <CartesianGrid
                    strokeDasharray="3 3"
                    className="stroke-border"
                    vertical={false}
                  />
                  <XAxis
                    dataKey="label"
                    tick={{ fontSize: 11 }}
                    tickLine={false}
                    axisLine={false}
                  />
                  <YAxis
                    allowDecimals={false}
                    tick={{ fontSize: 11 }}
                    tickLine={false}
                    axisLine={false}
                  />
                  <Tooltip
                    contentStyle={{
                      fontSize: 12,
                      borderRadius: "8px",
                      border: "1px solid hsl(var(--border))",
                      background: "hsl(var(--popover))",
                      color: "hsl(var(--popover-foreground))",
                    }}
                    cursor={{ fill: "hsl(var(--muted))" }}
                  />
                  <Bar
                    dataKey="count"
                    name="Registrations"
                    fill={BAR_COLOR}
                    radius={[4, 4, 0, 0]}
                  />
                </BarChart>
              </ResponsiveContainer>
            </div>
          </AnimatedChart>
        </SectionErrorBoundary>

        {/* Status distribution pie chart */}
        <SectionErrorBoundary title="Status distribution chart">
          <AnimatedChart delay={0.25}>
            <div className="rounded-xl border border-border bg-card p-5 space-y-4">
              <div>
                <h2 className="text-sm font-semibold">
                  Status Distribution
                </h2>
                <p className="text-xs text-muted-foreground mt-0.5">
                  Breakdown of tenants by current status
                </p>
              </div>
              <ResponsiveContainer width="100%" height={260}>
                <PieChart>
                  <Pie
                    data={statusDistribution}
                    dataKey="value"
                    nameKey="name"
                    cx="50%"
                    cy="50%"
                    outerRadius={90}
                    innerRadius={50}
                    paddingAngle={3}
                    label={({ name, percent }) =>
                      `${name} ${(percent * 100).toFixed(0)}%`
                    }
                    labelLine={false}
                  >
                    {statusDistribution.map((entry, index) => (
                      <Cell key={`cell-${index}`} fill={entry.fill} />
                    ))}
                  </Pie>
                  <Tooltip
                    contentStyle={{
                      fontSize: 12,
                      borderRadius: "8px",
                      border: "1px solid hsl(var(--border))",
                      background: "hsl(var(--popover))",
                      color: "hsl(var(--popover-foreground))",
                    }}
                  />
                  <Legend
                    iconType="circle"
                    iconSize={8}
                    formatter={(value) => (
                      <span className="text-xs text-muted-foreground">
                        {value}
                      </span>
                    )}
                  />
                </PieChart>
              </ResponsiveContainer>
            </div>
          </AnimatedChart>
        </SectionErrorBoundary>
      </div>

      {/* Top tenants by activity table */}
      {analytics.top_tenants_by_activity.length > 0 && (
        <SectionErrorBoundary title="Top tenants table">
          <AnimatedChart delay={0.3}>
            <div className="rounded-xl border border-border bg-card overflow-hidden">
              <div className="px-5 py-4 border-b border-border flex items-center gap-2">
                <Activity
                  className="size-4 text-muted-foreground"
                  aria-hidden="true"
                />
                <h2 className="text-sm font-semibold">Top Tenants by Activity</h2>
              </div>
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="border-b border-border bg-muted/30">
                      <th className="px-5 py-3 text-left text-xs font-medium uppercase tracking-wide text-muted-foreground">
                        Tenant
                      </th>
                      <th className="px-5 py-3 text-right text-xs font-medium uppercase tracking-wide text-muted-foreground">
                        PRs
                      </th>
                      <th className="px-5 py-3 text-right text-xs font-medium uppercase tracking-wide text-muted-foreground">
                        POs
                      </th>
                      <th className="px-5 py-3 text-right text-xs font-medium uppercase tracking-wide text-muted-foreground">
                        Total Spend
                      </th>
                    </tr>
                  </thead>
                  <tbody>
                    {analytics.top_tenants_by_activity.map((row, idx) => (
                      <tr
                        key={row.tenant_id}
                        className="border-b border-border last:border-0 hover:bg-muted/30 transition-colors"
                      >
                        <td className="px-5 py-3">
                          <div className="flex items-center gap-3">
                            <div
                              className="flex size-7 shrink-0 items-center justify-center rounded-md bg-primary/10 text-xs font-semibold text-primary"
                              aria-hidden="true"
                            >
                              {idx + 1}
                            </div>
                            <div>
                              <a
                                href={`/admin/tenants/${row.tenant_id}`}
                                className="font-medium text-foreground hover:underline focus:outline-none focus-visible:underline"
                              >
                                {row.tenant_name}
                              </a>
                              <p className="text-xs font-mono text-muted-foreground">
                                {row.tenant_code}
                              </p>
                            </div>
                          </div>
                        </td>
                        <td className="px-5 py-3 text-right tabular-nums">
                          {row.pr_count.toLocaleString()}
                        </td>
                        <td className="px-5 py-3 text-right tabular-nums">
                          {row.po_count.toLocaleString()}
                        </td>
                        <td className="px-5 py-3 text-right tabular-nums font-medium">
                          {Number(row.total_spend).toLocaleString("en-US", {
                            style: "currency",
                            currency: "USD",
                            minimumFractionDigits: 0,
                            maximumFractionDigits: 0,
                          })}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          </AnimatedChart>
        </SectionErrorBoundary>
      )}
    </div>
  );
}

// ─── Page ─────────────────────────────────────────────────────────────────────

export default function TenantAnalyticsPage() {
  return (
    <div className="space-y-6">
      {/* Page header */}
      <div className="flex items-start gap-4">
        <div
          className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary/10"
          aria-hidden="true"
        >
          <Activity className="size-5 text-primary" />
        </div>
        <div>
          <h1 className="text-xl font-semibold tracking-tight">
            Tenant Analytics
          </h1>
          <p className="mt-0.5 text-sm text-muted-foreground">
            Aggregated platform-wide metrics. Individual tenant data is not
            exposed to other tenants.
          </p>
        </div>
      </div>

      <SectionErrorBoundary title="Analytics dashboard">
        <AnalyticsDashboardContent />
      </SectionErrorBoundary>
    </div>
  );
}
