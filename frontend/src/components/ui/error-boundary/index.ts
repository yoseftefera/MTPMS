/**
 * Barrel export for React Error Boundary components.
 *
 * Components live in `src/components/ui/` and are re-exported here:
 *
 *   import { PageErrorBoundary, SectionErrorBoundary } from "@/components/ui/error-boundary"
 *
 * Components:
 *   PageErrorBoundary    — full-page error card with "Try again" (page reload)
 *   SectionErrorBoundary — inline error strip with "Retry" (state reset only)
 *
 * Usage pattern — wrap any data-fetching section:
 *
 *   <SectionErrorBoundary title="Purchase Requests">
 *     <PurchaseRequestTable />
 *   </SectionErrorBoundary>
 *
 *   <PageErrorBoundary>
 *     <DashboardPage />
 *   </PageErrorBoundary>
 *
 * Validates: Requirements 22.5, 22.7
 */

export { PageErrorBoundary } from "../PageErrorBoundary";
export { SectionErrorBoundary } from "../SectionErrorBoundary";
