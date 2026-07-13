/**
 * Streaming loading UI for the Goods Receipt detail page.
 * Validates: Requirements 22.5
 */
import { DetailPageSkeleton } from "@/components/ui/DetailPageSkeleton";
import { Skeleton } from "@/components/ui/skeleton";

export default function GoodsReceiptDetailLoading() {
  return (
    <div className="space-y-6">
      {/* Back link skeleton */}
      <Skeleton className="h-4 w-36" />

      {/* Detail skeleton */}
      <DetailPageSkeleton mainLines={6} sidebarCards={2} />
    </div>
  );
}
