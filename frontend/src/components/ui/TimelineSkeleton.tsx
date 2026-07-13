"use client";

/**
 * TimelineSkeleton — loading skeleton for history and timeline sections.
 *
 * Renders a vertical timeline placeholder with N entries, each showing
 * an avatar/icon circle, a title line, a description line, and a
 * timestamp on the trailing edge. Designed for:
 *   - Purchase Request history timelines
 *   - Approval workflow audit trails
 *   - Contract amendment histories
 *   - Any chronological event list
 *
 * Props:
 *   entries   — number of timeline items to render (default: 5)
 *   className — additional Tailwind classes for the outer wrapper
 *
 * Usage:
 *   {isLoading ? <TimelineSkeleton entries={6} /> : <PRHistoryTimeline history={data} />}
 *
 * Validates: Requirements 22.5
 */

import { cn } from "@/lib/utils";
import { Skeleton } from "@/components/ui/skeleton";

interface TimelineSkeletonProps {
  entries?: number;
  className?: string;
}

// Alternate description-line widths to look more natural
const DESC_WIDTHS = ["w-3/4", "w-2/3", "w-5/6", "w-1/2", "w-4/5"];

export function TimelineSkeleton({
  entries = 5,
  className,
}: TimelineSkeletonProps) {
  return (
    <div
      className={cn("space-y-0", className)}
      aria-hidden="true"
      aria-label="Loading timeline"
    >
      {Array.from({ length: entries }).map((_, i) => {
        const isLast = i === entries - 1;
        return (
          <div key={i} className="flex gap-3">
            {/* Left column: circle + connector line */}
            <div className="flex flex-col items-center">
              <Skeleton className="size-8 rounded-full shrink-0" />
              {!isLast && (
                <div className="w-px flex-1 my-1 bg-border min-h-[1.5rem]" />
              )}
            </div>

            {/* Right column: content */}
            <div
              className={cn(
                "flex-1 pb-6",
                isLast && "pb-0",
              )}
            >
              {/* Title + timestamp row */}
              <div className="flex items-start justify-between gap-2 mb-1.5">
                <Skeleton className="h-3.5 w-40" />
                <Skeleton className="h-3 w-16 shrink-0" />
              </div>
              {/* Description line */}
              <Skeleton
                className={cn(
                  "h-3",
                  DESC_WIDTHS[i % DESC_WIDTHS.length],
                )}
              />
              {/* Optional badge/tag — shown on every other entry */}
              {i % 2 === 0 && (
                <Skeleton className="mt-2 h-5 w-20 rounded-full" />
              )}
            </div>
          </div>
        );
      })}
    </div>
  );
}
