/**
 * Streaming loading UI for the Dashboard page.
 * Shown instantly by Next.js App Router while the page component loads.
 * Validates: Requirements 22.5
 */
import { DashboardSkeleton } from "@/components/ui/DashboardSkeleton";
import { Skeleton } from "@/components/ui/skeleton";

export default function DashboardLoading() {
  return (
    <div className="space-y-8">
      {/* Page header skeleton */}
      <div className="flex items-center justify-between">
        <div className="space-y-1.5">
          <Skeleton className="h-7 w-28" />
          <Skeleton className="h-4 w-48" />
        </div>
        <Skeleton className="h-9 w-24 rounded-md" />
      </div>

      {/* KPI cards + charts */}
      <DashboardSkeleton kpiCards={6} charts={2} />
    </div>
  );
}
