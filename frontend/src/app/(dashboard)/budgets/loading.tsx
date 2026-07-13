/**
 * Streaming loading UI for the Budget Management page.
 * Validates: Requirements 22.5
 */
import { TableSkeleton } from "@/components/ui/TableSkeleton";
import {
  Table,
  TableHeader,
  TableRow,
  TableHead,
  TableBody,
} from "@/components/ui/table";
import { Skeleton } from "@/components/ui/skeleton";

export default function BudgetsLoading() {
  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="space-y-1.5">
        <Skeleton className="h-7 w-48" />
        <Skeleton className="h-4 w-64" />
      </div>

      {/* Toolbar skeleton */}
      <div className="flex flex-wrap items-center justify-between gap-3">
        <Skeleton className="h-9 w-44 rounded-md" />
        <div className="flex items-center gap-2">
          <Skeleton className="h-9 w-44 rounded-md" />
          <Skeleton className="h-9 w-36 rounded-md" />
        </div>
      </div>

      {/* Table */}
      <div className="rounded-xl border border-border bg-card">
        <Table>
          <TableHeader>
            <TableRow>
              {["Department", "Fiscal Year", "Total Allocated", "Encumbered", "Spent", "Available", "Utilization", "Currency"].map((h) => (
                <TableHead key={h}>{h}</TableHead>
              ))}
            </TableRow>
          </TableHeader>
          <TableBody>
            <TableSkeleton rows={6} columns={8} />
          </TableBody>
        </Table>
      </div>
    </div>
  );
}
