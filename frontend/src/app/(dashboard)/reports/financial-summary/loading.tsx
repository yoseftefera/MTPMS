/**
 * Streaming loading UI for the Financial Summary report page.
 * Validates: Requirements 22.5
 */
import { DashboardSkeleton } from "@/components/ui/DashboardSkeleton";
import { Skeleton } from "@/components/ui/skeleton";

export default function FinancialSummaryLoading() {
  return (
    <div className="space-y-6">
      {/* Header + export */}
      <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div className="space-y-1.5">
          <Skeleton className="h-7 w-44" />
          <Skeleton className="h-4 w-72" />
        </div>
        <div className="flex gap-2">
          <Skeleton className="h-9 w-28 rounded-md" />
          <Skeleton className="h-9 w-28 rounded-md" />
        </div>
      </div>

      {/* Filters skeleton */}
      <div className="flex flex-wrap gap-2">
        <Skeleton className="h-9 w-36 rounded-md" />
        <Skeleton className="h-9 w-36 rounded-md" />
      </div>

      {/* KPI cards + charts */}
      <DashboardSkeleton kpiCards={4} charts={1} />
    </div>
  );
}
