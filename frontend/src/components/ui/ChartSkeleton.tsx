"use client";

/**
 * ChartSkeleton — loading skeleton for Recharts chart sections.
 *
 * Renders a card-shaped placeholder with a title/subtitle area and a
 * large chart canvas region. Designed to be swapped in while the query
 * that feeds the chart is still loading.
 *
 * Props:
 *   height    — height of the chart canvas area in pixels (default: 256)
 *   title     — show a title skeleton above the chart (default: true)
 *   legend    — show a legend skeleton below the chart (default: false)
 *   className — additional Tailwind classes for the outer wrapper
 *
 * Usage:
 *   {isLoading ? <ChartSkeleton height={300} legend /> : <BudgetBarChart data={data} />}
 *
 * Validates: Requirements 22.5
 */

import { cn } from "@/lib/utils";
import { Skeleton } from "@/components/ui/skeleton";

interface ChartSkeletonProps {
  height?: number;
  title?: boolean;
  legend?: boolean;
  className?: string;
}

export function ChartSkeleton({
  height = 256,
  title = true,
  legend = false,
  className,
}: ChartSkeletonProps) {
  return (
    <div
      className={cn(
        "rounded-xl border border-border bg-card p-5 space-y-4",
        className,
      )}
      aria-hidden="true"
      aria-label="Loading chart"
    >
      {/* Title / subtitle */}
      {title && (
        <div className="space-y-1.5">
          <Skeleton className="h-5 w-44" />
          <Skeleton className="h-3.5 w-64" />
        </div>
      )}

      {/* Chart canvas placeholder */}
      <Skeleton
        className="w-full rounded-lg"
        style={{ height: `${height}px` }}
      />

      {/* Optional legend row */}
      {legend && (
        <div className="flex flex-wrap items-center gap-4 pt-1">
          {[80, 68, 56, 72].map((w, i) => (
            <div key={i} className="flex items-center gap-1.5">
              <Skeleton className="size-3 rounded-sm shrink-0" />
              <Skeleton className={`h-3`} style={{ width: `${w}px` }} />
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
