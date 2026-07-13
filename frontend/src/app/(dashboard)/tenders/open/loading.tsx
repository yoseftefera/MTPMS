/**
 * Streaming loading UI for the Open Tenders (supplier-facing) page.
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

export default function OpenTendersLoading() {
  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="space-y-1.5">
        <Skeleton className="h-7 w-40" />
        <Skeleton className="h-4 w-72" />
      </div>

      {/* Toolbar skeleton */}
      <div className="flex flex-wrap gap-2">
        <Skeleton className="h-9 w-56 rounded-md" />
        <Skeleton className="h-9 w-44 rounded-md" />
      </div>

      {/* Table */}
      <div className="rounded-xl border border-border bg-card">
        <Table>
          <TableHeader>
            <TableRow>
              {["Reference #", "Title", "Category", "Est. Value", "Deadline", "My Bid Status", ""].map((h) => (
                <TableHead key={h}>{h}</TableHead>
              ))}
            </TableRow>
          </TableHeader>
          <TableBody>
            <TableSkeleton rows={8} columns={7} />
          </TableBody>
        </Table>
      </div>
    </div>
  );
}
