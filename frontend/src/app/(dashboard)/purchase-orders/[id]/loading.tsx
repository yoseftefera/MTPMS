/**
 * Streaming loading UI for the Purchase Order detail page.
 * Validates: Requirements 22.5
 */
import { DetailPageSkeleton } from "@/components/ui/DetailPageSkeleton";
import { Skeleton } from "@/components/ui/skeleton";

export default function PurchaseOrderDetailLoading() {
  return (
    <div className="space-y-6">
      {/* Back link skeleton */}
      <Skeleton className="h-4 w-36" />

      {/* Detail skeleton */}
      <DetailPageSkeleton mainLines={8} sidebarCards={2} />
    </div>
  );
}
