/**
 * Streaming loading UI for the Notifications history page.
 * Validates: Requirements 22.5
 */
import { Skeleton } from "@/components/ui/skeleton";

export default function NotificationsLoading() {
  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="space-y-1.5">
        <Skeleton className="h-7 w-40" />
        <Skeleton className="h-4 w-56" />
      </div>

      {/* Toolbar skeleton */}
      <div className="flex flex-wrap gap-2">
        <Skeleton className="h-9 w-48 rounded-md" />
        <Skeleton className="h-9 w-36 rounded-md" />
        <Skeleton className="h-9 w-36 rounded-md ml-auto" />
      </div>

      {/* Notification list skeleton */}
      <div className="space-y-2">
        {Array.from({ length: 8 }).map((_, i) => (
          <div
            key={i}
            className="rounded-xl border border-border bg-card p-4 flex items-start gap-3"
            aria-hidden="true"
          >
            <Skeleton className="size-8 rounded-full shrink-0 mt-0.5" />
            <div className="flex-1 space-y-1.5 min-w-0">
              <Skeleton className={`h-4 ${i % 3 === 0 ? "w-3/4" : i % 3 === 1 ? "w-2/3" : "w-5/6"}`} />
              <Skeleton className="h-3 w-full" />
              <Skeleton className="h-3 w-1/3" />
            </div>
            <Skeleton className="h-5 w-12 rounded-full shrink-0" />
          </div>
        ))}
      </div>
    </div>
  );
}
