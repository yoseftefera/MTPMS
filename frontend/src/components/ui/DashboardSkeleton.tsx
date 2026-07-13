"use client";

/**
 * DashboardSkeleton — loading skeleton for dashboard / analytics pages.
 *
 * Renders:
 *   - A row of KPI card skeletons
 *   - Optional chart area skeleton(s)
 *
 * Props:
 *   kpiCards  — number of KPI card skeletons (default: 6)
 *   charts    — number of chart placeholder skeletons (default: 2)
 *   className — additional Tailwind classes for the outer wrapper
 *
 * Usage:
 *   {isLoading ? <DashboardSkeleton /> : <DashboardContent />}
 *
 * Validates: Requirements 22.5
 */

import { cn } from "@/lib/utils";
import { Skeleton } from "@/components/ui/skeleton";

interface DashboardSkeletonProps {
  kpiCards?: number;
  charts?: number;
  className?: string;
}

export function DashboardSkeleton({
  kpiCards = 6,
  charts = 2,
  className,
}: DashboardSkeletonProps) {
  return (
    <div className={cn("space-y-8", className)} aria-hidden="true" aria-label="Loading dashboard">
      {/* KPI cards row */}
      <div
        className="grid grid-cols-2 gap-4 sm:grid-cols-3 xl:grid-cols-6"
        aria-hidden="true"
      >
        {Array.from({ length: kpiCards }).map((_, i) => (
          <div
            key={i}
            className="rounded-xl border border-border bg-card p-5 space-y-3"
          >
            <div className="flex items-center gap-2">
              {/* Icon placeholder */}
              <Skeleton className="size-8 rounded-lg shrink-0" />
              <Skeleton className="h-3.5 w-20" />
            </div>
            <Skeleton className="h-8 w-16" />
            <Skeleton className="h-3 w-24" />
          </div>
        ))}
      </div>

      {/* Charts row */}
      {charts > 0 && (
        <div
          className={`grid grid-cols-1 gap-6 ${charts > 1 ? "lg:grid-cols-2" : ""}`}
          aria-hidden="true"
        >
          {Array.from({ length: charts }).map((_, i) => (
            <div
              key={i}
              className="rounded-xl border border-border bg-card p-5 space-y-3"
            >
              <Skeleton className="h-5 w-40" />
              <Skeleton className="h-3 w-56" />
              <Skeleton className="h-64 w-full rounded-lg" />
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
