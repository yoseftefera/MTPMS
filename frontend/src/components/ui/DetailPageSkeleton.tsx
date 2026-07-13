"use client";

/**
 * DetailPageSkeleton — loading skeleton for entity detail pages.
 *
 * Renders a common detail-page structure:
 *   - Header with back link, title, status badge, and action buttons
 *   - A two-column layout: main info card + sidebar cards
 *
 * Props:
 *   mainLines    — lines in the main info card (default: 6)
 *   sidebarCards — number of sidebar card skeletons (default: 2)
 *   className    — additional Tailwind classes for the outer wrapper
 *
 * Usage:
 *   {isLoading ? <DetailPageSkeleton /> : <PurchaseOrderDetail po={data} />}
 *
 * Validates: Requirements 22.5
 */

import { cn } from "@/lib/utils";
import { Skeleton } from "@/components/ui/skeleton";

interface DetailPageSkeletonProps {
  mainLines?: number;
  sidebarCards?: number;
  className?: string;
}

// Vary line widths for a more natural look
const LINE_WIDTHS = ["w-full", "w-3/4", "w-5/6", "w-2/3", "w-full", "w-4/5"];

export function DetailPageSkeleton({
  mainLines = 6,
  sidebarCards = 2,
  className,
}: DetailPageSkeletonProps) {
  return (
    <div className={cn("space-y-6", className)} aria-hidden="true" aria-label="Loading details">
      {/* Page header skeleton */}
      <div className="flex items-start justify-between gap-4">
        <div className="space-y-2">
          {/* Back link */}
          <Skeleton className="h-4 w-28" />
          {/* Title */}
          <Skeleton className="h-7 w-64" />
          {/* Subtitle / status */}
          <div className="flex items-center gap-2">
            <Skeleton className="h-5 w-24 rounded-full" />
            <Skeleton className="h-4 w-32" />
          </div>
        </div>
        {/* Action buttons */}
        <div className="flex gap-2 shrink-0">
          <Skeleton className="h-9 w-24 rounded-md" />
          <Skeleton className="h-9 w-20 rounded-md" />
        </div>
      </div>

      {/* Content grid */}
      <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
        {/* Main info card */}
        <div className="lg:col-span-2 rounded-xl border border-border bg-card p-6 space-y-4">
          {/* Card header */}
          <div className="flex items-center gap-3 pb-3 border-b border-border">
            <Skeleton className="size-8 rounded-lg shrink-0" />
            <Skeleton className="h-4 w-36" />
          </div>

          {/* Detail rows */}
          <div className="grid grid-cols-2 gap-4">
            {Array.from({ length: mainLines }).map((_, i) => (
              <div key={i} className="space-y-1">
                <Skeleton className="h-3 w-20" />
                <Skeleton className={cn("h-4", LINE_WIDTHS[i % LINE_WIDTHS.length])} />
              </div>
            ))}
          </div>
        </div>

        {/* Sidebar */}
        <div className="space-y-4">
          {Array.from({ length: sidebarCards }).map((_, i) => (
            <div key={i} className="rounded-xl border border-border bg-card p-5 space-y-3">
              <Skeleton className="h-4 w-28 mb-2" />
              {Array.from({ length: 3 }).map((_, j) => (
                <div key={j} className="space-y-1">
                  <Skeleton className="h-3 w-16" />
                  <Skeleton className="h-4 w-full" />
                </div>
              ))}
            </div>
          ))}
        </div>
      </div>

      {/* Timeline / history section */}
      <div className="rounded-xl border border-border bg-card p-6 space-y-4">
        <Skeleton className="h-4 w-32" />
        {Array.from({ length: 3 }).map((_, i) => (
          <div key={i} className="flex gap-3 items-start">
            <Skeleton className="size-7 rounded-full shrink-0 mt-0.5" />
            <div className="space-y-1.5 flex-1">
              <Skeleton className="h-3.5 w-48" />
              <Skeleton className="h-3 w-64" />
            </div>
            <Skeleton className="h-3 w-16 shrink-0 mt-1" />
          </div>
        ))}
      </div>
    </div>
  );
}
