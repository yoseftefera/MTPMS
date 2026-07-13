/**
 * Barrel export for all skeleton loading components.
 *
 * All skeleton components live in `src/components/ui/` and are
 * re-exported here so consumers can import from a single path:
 *
 *   import { TableSkeleton, ChartSkeleton } from "@/components/ui/skeletons"
 *
 * Components:
 *   TableSkeleton      — paginated data table rows (users, PRs, POs, etc.)
 *   CardSkeleton       — generic content card
 *   FormSkeleton       — form page while reference data loads
 *   DetailPageSkeleton — full entity detail page (header + main + sidebar)
 *   DashboardSkeleton  — KPI cards row + chart placeholders
 *   TimelineSkeleton   — history / audit trail timeline entries
 *   ChartSkeleton      — Recharts chart panel (canvas + optional legend)
 *
 * Validates: Requirements 22.5
 */

export { Skeleton } from "../skeleton";
export { TableSkeleton } from "../TableSkeleton";
export { CardSkeleton } from "../CardSkeleton";
export { FormSkeleton } from "../FormSkeleton";
export { DetailPageSkeleton } from "../DetailPageSkeleton";
export { DashboardSkeleton } from "../DashboardSkeleton";
export { TimelineSkeleton } from "../TimelineSkeleton";
export { ChartSkeleton } from "../ChartSkeleton";
