"use client";

/**
 * FormSkeleton — loading skeleton for form pages.
 *
 * Renders a card with a title area and N field groups (each with a label
 * skeleton above and an input skeleton below), plus an optional action
 * button skeleton at the bottom.
 *
 * Props:
 *   fields    — number of form fields to render (default: 5)
 *   hasSubmit — whether to show a submit-button skeleton (default: true)
 *   className — additional Tailwind classes for the outer wrapper
 *
 * Usage:
 *   {isLoading ? <FormSkeleton fields={6} /> : <CreateForm />}
 *
 * Validates: Requirements 22.5
 */

import { cn } from "@/lib/utils";
import { Skeleton } from "@/components/ui/skeleton";

interface FormSkeletonProps {
  fields?: number;
  hasSubmit?: boolean;
  className?: string;
}

export function FormSkeleton({
  fields = 5,
  hasSubmit = true,
  className,
}: FormSkeletonProps) {
  return (
    <div
      className={cn(
        "rounded-xl border border-border bg-card p-6 space-y-6",
        className,
      )}
      aria-hidden="true"
      aria-label="Loading form"
    >
      {/* Form title area */}
      <div className="space-y-1.5 pb-4 border-b border-border">
        <Skeleton className="h-5 w-48" />
        <Skeleton className="h-3.5 w-64" />
      </div>

      {/* Field groups */}
      <div className="space-y-5">
        {Array.from({ length: fields }).map((_, i) => (
          <div key={i} className="space-y-1.5">
            {/* Label */}
            <Skeleton className={`h-3.5 ${i % 3 === 0 ? "w-24" : i % 3 === 1 ? "w-32" : "w-20"}`} />
            {/* Input */}
            <Skeleton className="h-9 w-full rounded-md" />
          </div>
        ))}
      </div>

      {/* Action row */}
      {hasSubmit && (
        <div className="flex justify-end gap-2 pt-2 border-t border-border">
          <Skeleton className="h-9 w-20 rounded-md" />
          <Skeleton className="h-9 w-24 rounded-md" />
        </div>
      )}
    </div>
  );
}
