/**
 * Streaming loading UI for the Budget Utilization dashboard page.
 * Validates: Requirements 22.5
 */
import { DashboardSkeleton } from "@/components/ui/DashboardSkeleton";
import { Skeleton } from "@/components/ui/skeleton";

export default function BudgetUtilizationLoading() {
  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="space-y-1.5">
        <Skeleton className="h-7 w-56" />
        <Skeleton className="h-4 w-80" />
      </div>

      {/* Filters skeleton */}
      <div className="flex flex-wrap gap-2">
        <Skeleton className="h-9 w-44 rounded-md" />
        <Skeleton className="h-9 w-44 rounded-md" />
      </div>

      {/* KPI cards + charts */}
      <DashboardSkeleton kpiCards={4} charts={2} />
    </div>
  );
}
