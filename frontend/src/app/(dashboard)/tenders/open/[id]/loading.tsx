/**
 * Streaming loading UI for the Open Tender detail / bid submission page.
 * Validates: Requirements 22.5
 */
import { DetailPageSkeleton } from "@/components/ui/DetailPageSkeleton";
import { Skeleton } from "@/components/ui/skeleton";

export default function OpenTenderDetailLoading() {
  return (
    <div className="space-y-6">
      {/* Back link skeleton */}
      <Skeleton className="h-4 w-32" />

      {/* Detail skeleton */}
      <DetailPageSkeleton mainLines={6} sidebarCards={2} />

      {/* Bid form skeleton */}
      <div className="rounded-xl border border-border bg-card p-6 space-y-4">
        <Skeleton className="h-5 w-36" />
        <div className="space-y-4">
          {Array.from({ length: 4 }).map((_, i) => (
            <div key={i} className="space-y-1.5">
              <Skeleton className={`h-3.5 ${i % 2 === 0 ? "w-28" : "w-20"}`} />
              <Skeleton className="h-9 w-full rounded-md" />
            </div>
          ))}
        </div>
        <div className="flex justify-end">
          <Skeleton className="h-9 w-32 rounded-md" />
        </div>
      </div>
    </div>
  );
}
