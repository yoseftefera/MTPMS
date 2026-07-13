"use client";

/**
 * Role-specific dashboard page.
 *
 * Displays:
 *   - KPI widget cards (total PRs by status, active tenders, PO fulfillment
 *     rate, budget utilization %, pending approvals, overdue deliveries)
 *   - Recharts bar chart: PR status breakdown
 *   - Recharts line chart: monthly spend trend
 *   - Role-based conditional rendering of sections
 *
 * Chart containers are wrapped with Framer Motion (AnimatedChart) for
 * entrance animations. KpiCards animate individually with staggered delays.
 *
 * Validates: Requirements 16.1, 16.10, 22.1, 22.10, 3.7
 */

import { useMemo } from "react";
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
  Cell,
  Legend,
} from "recharts";
import {
  FileText,
  Gavel,
  ShoppingCart,
  PiggyBank,
  Clock,
  AlertTriangle,
  TrendingUp,
} from "lucide-react";
import { Skeleton } from "@/components/ui/skeleton";
import { Button } from "@/components/ui/button";
import { RefreshCw } from "lucide-react";
import { KpiCard } from "@/components/reports/KpiCard";
import { AnimatedChart } from "@/components/reports/AnimatedChart";
import { useDashboardKPIs } from "@/hooks/useReporting";
import { useSpendingAnalytics } from "@/hooks/useReporting";
import { useAuthStore } from "@/store/authStore";
import { formatCurrency, formatPercent } from "@/lib/utils";
import { SectionErrorBoundary } from "@/components/ui/SectionErrorBoundary";

// ─── Constants ────────────────────────────────────────────────────────────────

const PR_STATUS_COLORS: Record<string, string> = {
  draft: "#94a3b8",
  pending_approval: "#f59e0b",
  approved: "#22c55e",
  rejected: "#ef4444",
  revision_required: "#f97316",
  cancelled: "#64748b",
};

// Roles that see the financial/budget sections
const FINANCE_ROLES = new Set(["Finance_Officer", "Tenant_Admin"]);
// Roles that see the procurement sections
const PROCUREMENT_ROLES = new Set(["Procurement_Officer", "Tenant_Admin", "Department_Staff"]);

// ─── Skeleton loaders ─────────────────────────────────────────────────────────

function KpiSkeletons() {
  return (
    <>
      {Array.from({ length: 6 }).map((_, i) => (
        <div key={i} className="rounded-xl border bg-card p-5 space-y-3">
          <Skeleton className="h-4 w-28" />
          <Skeleton className="h-8 w-16" />
          <Skeleton className="h-3 w-20" />
        </div>
      ))}
    </>
  );
}

function ChartSkeleton({ height = 280 }: { height?: number }) {
  return (
    <div className="rounded-xl border bg-card p-5 space-y-3">
      <Skeleton className="h-5 w-40" />
      <Skeleton className="h-3 w-60" />
      <Skeleton style={{ height }} className="w-full rounded-lg" />
    </div>
  );
}

// ─── PR Status Bar Chart ──────────────────────────────────────────────────────

interface PRStatusChartProps {
  data: Record<string, number>;
}

function PRStatusChart({ data }: PRStatusChartProps) {
  const chartData = Object.entries(data).map(([status, count]) => ({
    status: status.replace(/_/g, " "),
    count,
    fill: PR_STATUS_COLORS[status] ?? "#6366f1",
  }));

  return (
    <AnimatedChart delay={0.1}>
      <div className="rounded-xl border bg-card p-5">
        <h2 className="text-base font-semibold">Purchase Requests by Status</h2>
        <p className="mt-0.5 mb-4 text-xs text-muted-foreground">
          Current distribution across all PR statuses
        </p>
        <ResponsiveContainer width="100%" height={260}>
          <BarChart data={chartData} margin={{ top: 4, right: 8, bottom: 4, left: 0 }}>
            <CartesianGrid strokeDasharray="3 3" vertical={false} className="stroke-border" />
            <XAxis
              dataKey="status"
              tick={{ fontSize: 11 }}
              tickLine={false}
              axisLine={false}
            />
            <YAxis
              allowDecimals={false}
              tick={{ fontSize: 11 }}
              tickLine={false}
              axisLine={false}
              width={32}
            />
            <Tooltip
              cursor={{ fill: "hsl(var(--muted))" }}
              contentStyle={{
                borderRadius: 8,
                border: "1px solid hsl(var(--border))",
                background: "hsl(var(--card))",
                fontSize: 12,
              }}
            />
            <Bar dataKey="count" radius={[4, 4, 0, 0]} maxBarSize={48}>
              {chartData.map((entry, index) => (
                <Cell key={index} fill={entry.fill} />
              ))}
            </Bar>
          </BarChart>
        </ResponsiveContainer>
      </div>
    </AnimatedChart>
  );
}

// ─── Monthly Spend Line Chart ─────────────────────────────────────────────────

interface MonthlySpendChartProps {
  data: Array<{ month: string; amount: string; previous_amount: string }>;
  currency: string;
}

function MonthlySpendChart({ data, currency }: MonthlySpendChartProps) {
  const chartData = data.map((d) => ({
    month: d.month,
    current: parseFloat(d.amount),
    previous: parseFloat(d.previous_amount),
  }));

  return (
    <AnimatedChart delay={0.2}>
      <div className="rounded-xl border bg-card p-5">
        <h2 className="text-base font-semibold">Monthly Spend Trend</h2>
        <p className="mt-0.5 mb-4 text-xs text-muted-foreground">
          Current vs. prior period spend by month
        </p>
        <ResponsiveContainer width="100%" height={260}>
          <LineChart data={chartData} margin={{ top: 4, right: 16, bottom: 4, left: 0 }}>
            <CartesianGrid strokeDasharray="3 3" vertical={false} className="stroke-border" />
            <XAxis
              dataKey="month"
              tick={{ fontSize: 11 }}
              tickLine={false}
              axisLine={false}
            />
            <YAxis
              tick={{ fontSize: 11 }}
              tickLine={false}
              axisLine={false}
              width={60}
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
              formatter={(value) =>
                value === "current" ? "This Period" : "Prior Period"
              }
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
              activeDot={{ r: 5 }}
            />
          </LineChart>
        </ResponsiveContainer>
      </div>
    </AnimatedChart>
  );
}

// ─── Budget Utilization Bar Chart ─────────────────────────────────────────────

interface BudgetUtilizationChartProps {
  departments: Array<{
    label: string;
    amount: string;
    percentage: string;
  }>;
  currency: string;
}

function BudgetUtilizationChart({ departments, currency }: BudgetUtilizationChartProps) {
  const chartData = departments.slice(0, 8).map((d) => ({
    name: d.label.length > 16 ? d.label.slice(0, 14) + "…" : d.label,
    fullName: d.label,
    spent: parseFloat(d.amount),
    utilization: parseFloat(d.percentage),
  }));

  return (
    <AnimatedChart delay={0.3}>
      <div className="rounded-xl border bg-card p-5">
        <h2 className="text-base font-semibold">Budget Utilization by Department</h2>
        <p className="mt-0.5 mb-4 text-xs text-muted-foreground">
          Spend amount for current fiscal year (top 8 departments)
        </p>
        <ResponsiveContainer width="100%" height={260}>
          <BarChart
            data={chartData}
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
              dataKey="name"
              tick={{ fontSize: 11 }}
              tickLine={false}
              axisLine={false}
              width={80}
            />
            <Tooltip
              contentStyle={{
                borderRadius: 8,
                border: "1px solid hsl(var(--border))",
                background: "hsl(var(--card))",
                fontSize: 12,
              }}
              formatter={(value: number, _name, props) => [
                `${formatCurrency(value, currency)} (${props.payload?.utilization?.toFixed(1)}%)`,
                "Spent",
              ]}
              labelFormatter={(_label, payload) =>
                payload?.[0]?.payload?.fullName ?? _label
              }
            />
            <Bar dataKey="spent" radius={[0, 4, 4, 0]} maxBarSize={24}>
              {chartData.map((entry, index) => (
                <Cell
                  key={index}
                  fill={
                    entry.utilization >= 90
                      ? "#ef4444"
                      : entry.utilization >= 75
                        ? "#f59e0b"
                        : "#6366f1"
                  }
                />
              ))}
            </Bar>
          </BarChart>
        </ResponsiveContainer>
      </div>
    </AnimatedChart>
  );
}

// ─── Main Dashboard Page ──────────────────────────────────────────────────────

export default function DashboardPage() {
  const role = useAuthStore((s) => s.role) ?? "";

  const {
    data: kpiData,
    isLoading: kpiLoading,
    isError: kpiError,
    refetch: refetchKpis,
  } = useDashboardKPIs();

  const {
    data: spendData,
    isLoading: spendLoading,
  } = useSpendingAnalytics();

  const kpis = kpiData?.data;
  const spend = spendData?.data;

  // Total PRs
  const totalPRs = useMemo(
    () =>
      kpis
        ? Object.values(kpis.pr_by_status).reduce((a, b) => a + b, 0)
        : 0,
    [kpis],
  );

  const showFinance = FINANCE_ROLES.has(role);
  const showProcurement = PROCUREMENT_ROLES.has(role) || !role;

  return (
    <div className="space-y-8">
      {/* Page header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight">Dashboard</h1>
          <p className="mt-1 text-sm text-muted-foreground">
            {role
              ? `Overview for ${role.replace(/_/g, " ")}`
              : "Procurement overview"}
          </p>
        </div>
        <Button
          variant="outline"
          size="sm"
          onClick={() => refetchKpis()}
          aria-label="Refresh dashboard"
          className="gap-1.5"
        >
          <RefreshCw className="size-3.5" />
          Refresh
        </Button>
      </div>

      {/* ── KPI Cards ──────────────────────────────────────────────── */}
      <SectionErrorBoundary title="KPI indicators">
        <section aria-label="Key performance indicators">
          <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 xl:grid-cols-6">
          {kpiLoading ? (
            <KpiSkeletons />
          ) : kpiError ? (
            <div className="col-span-full rounded-xl border border-destructive/30 bg-destructive/5 p-6 text-center">
              <p className="text-sm text-muted-foreground mb-3">
                Failed to load KPIs.
              </p>
              <Button variant="outline" size="sm" onClick={() => refetchKpis()}>
                <RefreshCw className="size-3.5" />
                Retry
              </Button>
            </div>
          ) : kpis ? (
            <>
              <KpiCard
                label="Total Purchase Requests"
                value={totalPRs}
                subValue={`${kpis.pr_by_status.pending_approval ?? 0} pending approval`}
                icon={FileText}
                iconClassName="bg-blue-500/10 text-blue-600 dark:text-blue-400"
                delay={0}
              />

              <KpiCard
                label="Active Tenders"
                value={kpis.active_tenders}
                icon={Gavel}
                iconClassName="bg-violet-500/10 text-violet-600 dark:text-violet-400"
                delay={0.05}
              />

              <KpiCard
                label="PO Fulfillment Rate"
                value={`${parseFloat(kpis.po_fulfillment_rate).toFixed(1)}%`}
                subValue={
                  parseFloat(kpis.po_fulfillment_rate) >= 90
                    ? "On track"
                    : "Needs attention"
                }
                trend={
                  parseFloat(kpis.po_fulfillment_rate) >= 90 ? "up" : "down"
                }
                icon={ShoppingCart}
                iconClassName="bg-emerald-500/10 text-emerald-600 dark:text-emerald-400"
                delay={0.1}
              />

              {showFinance && (
                <KpiCard
                  label="Budget Utilization"
                  value={formatPercent(kpis.budget_utilization_percentage)}
                  subValue={
                    parseFloat(kpis.budget_utilization_percentage) >= 90
                      ? "Critical — review allocations"
                      : parseFloat(kpis.budget_utilization_percentage) >= 75
                        ? "High utilization"
                        : "Within targets"
                  }
                  trend={
                    parseFloat(kpis.budget_utilization_percentage) >= 90
                      ? "down"
                      : "neutral"
                  }
                  alert={parseFloat(kpis.budget_utilization_percentage) >= 90}
                  icon={PiggyBank}
                  iconClassName="bg-amber-500/10 text-amber-600 dark:text-amber-400"
                  delay={0.15}
                />
              )}

              <KpiCard
                label="Pending Approvals"
                value={kpis.pending_approvals_count}
                subValue={
                  kpis.pending_approvals_count > 0
                    ? "Awaiting your action"
                    : "All clear"
                }
                trend={kpis.pending_approvals_count > 0 ? "down" : "up"}
                alert={kpis.pending_approvals_count > 5}
                icon={Clock}
                iconClassName="bg-orange-500/10 text-orange-600 dark:text-orange-400"
                delay={0.2}
              />

              <KpiCard
                label="Overdue Deliveries"
                value={kpis.overdue_deliveries_count}
                subValue={
                  kpis.overdue_deliveries_count > 0
                    ? "POs past delivery date"
                    : "No overdue POs"
                }
                trend={kpis.overdue_deliveries_count > 0 ? "down" : "up"}
                alert={kpis.overdue_deliveries_count > 0}
                icon={AlertTriangle}
                iconClassName="bg-red-500/10 text-red-600 dark:text-red-400"
                delay={0.25}
              />
            </>
          ) : null}
        </div>
      </section>
      </SectionErrorBoundary>

      {/* ── Charts ─────────────────────────────────────────────────── */}
      <SectionErrorBoundary title="Analytics charts">
        <section aria-label="Analytics charts">
          <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
            {/* PR Status breakdown */}
            {showProcurement && (
              <>
                {kpiLoading ? (
                  <ChartSkeleton />
                ) : kpis?.pr_by_status ? (
                  <PRStatusChart data={kpis.pr_by_status} />
                ) : null}
              </>
            )}

            {/* Monthly spend trend */}
            {showFinance && (
              <>
                {spendLoading ? (
                  <ChartSkeleton />
                ) : spend?.monthly_trend && spend.monthly_trend.length > 0 ? (
                  <MonthlySpendChart
                    data={spend.monthly_trend}
                    currency={spend.currency}
                  />
                ) : null}
              </>
            )}
          </div>

          {/* Budget utilization by dept — finance roles only, full width */}
          {showFinance && (
            <div className="mt-6">
              {spendLoading ? (
                <ChartSkeleton height={300} />
              ) : spend?.by_department && spend.by_department.length > 0 ? (
                <BudgetUtilizationChart
                  departments={spend.by_department}
                  currency={spend.currency}
                />
              ) : null}
            </div>
          )}
        </section>
      </SectionErrorBoundary>

      {/* ── Quick links ─────────────────────────────────────────────── */}
      <section aria-label="Quick links">
        <div className="flex flex-wrap gap-2">
          {showProcurement && (
            <>
              <a
                href="/purchase-requests"
                className="inline-flex items-center gap-1.5 rounded-md border border-border bg-card px-3 py-1.5 text-sm font-medium text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
              >
                <FileText className="size-3.5" />
                Purchase Requests
              </a>
              <a
                href="/tenders"
                className="inline-flex items-center gap-1.5 rounded-md border border-border bg-card px-3 py-1.5 text-sm font-medium text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
              >
                <Gavel className="size-3.5" />
                Tenders
              </a>
            </>
          )}
          {showFinance && (
            <>
              <a
                href="/reports/spending"
                className="inline-flex items-center gap-1.5 rounded-md border border-border bg-card px-3 py-1.5 text-sm font-medium text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
              >
                <TrendingUp className="size-3.5" />
                Spending Report
              </a>
              <a
                href="/reports/financial-summary"
                className="inline-flex items-center gap-1.5 rounded-md border border-border bg-card px-3 py-1.5 text-sm font-medium text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
              >
                <PiggyBank className="size-3.5" />
                Financial Summary
              </a>
            </>
          )}
        </div>
      </section>
    </div>
  );
}
