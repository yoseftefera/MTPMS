/**
 * Streaming loading UI for the Tender detail page.
 * Validates: Requirements 22.5
 */
import { DetailPageSkeleton } from "@/components/ui/DetailPageSkeleton";
import { Skeleton } from "@/components/ui/skeleton";

export default function TenderDetailLoading() {
  return (
    <div className="space-y-6">
      {/* Back link skeleton */}
      <Skeleton className="h-4 w-28" />

      {/* Detail skeleton */}
      <DetailPageSkeleton mainLines={8} sidebarCards={2} />
    </div>
  );
}
