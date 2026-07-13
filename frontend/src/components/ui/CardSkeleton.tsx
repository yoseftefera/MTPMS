/**
 * CardSkeleton — reusable loading skeleton for detail pages and form areas.
 *
 * Renders a card-shaped placeholder with a header skeleton and N
 * content lines of varying widths.
 *
 * Props:
 *   lines     — number of content lines to show (default: 4)
 *   className — additional Tailwind classes applied to the outer card
 *
 * Usage:
 *   {isLoading ? <CardSkeleton lines={6} /> : <DetailCard data={data} />}
 *
 * Validates: Requirements 22.5
 */

import { cn } from "@/lib/utils";
import { Skeleton } from "@/components/ui/skeleton";

// Width class cycling so lines look varied rather than uniform
const LINE_WIDTHS = ["w-3/4", "w-full", "w-5/6", "w-2/3", "w-full", "w-4/5"];

interface CardSkeletonProps {
  lines?: number;
  className?: string;
}

export function CardSkeleton({ lines = 4, className }: CardSkeletonProps) {
  return (
    <div
      className={cn(
        "rounded-xl border border-border bg-card p-6 space-y-4",
        className,
      )}
      aria-hidden="true"
    >
      {/* Card header area */}
      <div className="flex items-center gap-3 pb-2 border-b border-border">
        <Skeleton className="size-9 rounded-lg shrink-0" />
        <div className="flex-1 space-y-1.5">
          <Skeleton className="h-4 w-40" />
          <Skeleton className="h-3 w-24" />
        </div>
      </div>

      {/* Content lines */}
      <div className="space-y-3">
        {Array.from({ length: lines }).map((_, i) => (
          <Skeleton
            key={i}
            className={cn("h-4", LINE_WIDTHS[i % LINE_WIDTHS.length])}
          />
        ))}
      </div>
    </div>
  );
}
