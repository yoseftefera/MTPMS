/**
 * Streaming loading UI for the Bid Evaluation page.
 * Validates: Requirements 22.5
 */
import { Skeleton } from "@/components/ui/skeleton";
import { TableSkeleton } from "@/components/ui/TableSkeleton";
import {
  Table,
  TableHeader,
  TableRow,
  TableHead,
  TableBody,
} from "@/components/ui/table";

export default function BidEvaluationLoading() {
  return (
    <div className="space-y-6">
      {/* Back link + header */}
      <Skeleton className="h-4 w-28" />
      <div className="space-y-1.5">
        <Skeleton className="h-7 w-52" />
        <Skeleton className="h-4 w-72" />
      </div>

      {/* Criteria form skeleton */}
      <div className="rounded-xl border border-border bg-card p-6 space-y-4">
        <Skeleton className="h-5 w-44" />
        <div className="space-y-3">
          {Array.from({ length: 3 }).map((_, i) => (
            <div key={i} className="flex items-center gap-3">
              <Skeleton className="h-9 flex-1 rounded-md" />
              <Skeleton className="h-9 w-20 rounded-md" />
              <Skeleton className="h-9 w-9 rounded-md" />
            </div>
          ))}
        </div>
      </div>

      {/* Scores grid skeleton */}
      <div className="rounded-xl border border-border bg-card overflow-hidden">
        <Table>
          <TableHeader>
            <TableRow>
              {["Bid", "Supplier", "Criteria 1", "Criteria 2", "Criteria 3", "Total Score", ""].map((h) => (
                <TableHead key={h}>{h}</TableHead>
              ))}
            </TableRow>
          </TableHeader>
          <TableBody>
            <TableSkeleton rows={5} columns={7} />
          </TableBody>
        </Table>
      </div>
    </div>
  );
}
