/**
 * Central re-export for all loading-state UI primitives:
 * skeleton components and error boundary components.
 *
 * Import from here to avoid scattered deep imports:
 *   import { TableSkeleton, PageErrorBoundary } from "@/components/ui/loading"
 *
 * Validates: Requirements 22.5, 22.7
 */

export { Skeleton } from "./skeleton";
export { TableSkeleton } from "./TableSkeleton";
export { CardSkeleton } from "./CardSkeleton";
export { FormSkeleton } from "./FormSkeleton";
export { DashboardSkeleton } from "./DashboardSkeleton";
export { DetailPageSkeleton } from "./DetailPageSkeleton";
export { TimelineSkeleton } from "./TimelineSkeleton";
export { ChartSkeleton } from "./ChartSkeleton";
export { PageErrorBoundary } from "./PageErrorBoundary";
export { SectionErrorBoundary } from "./SectionErrorBoundary";
