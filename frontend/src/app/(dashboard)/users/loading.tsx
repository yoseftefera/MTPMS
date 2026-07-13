/**
 * Streaming loading UI for the Users page.
 * Shown instantly by Next.js App Router while the page component loads.
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

export default function UsersLoading() {
  return (
    <div className="space-y-6">
      {/* Page header skeleton */}
      <div className="space-y-1.5">
        <Skeleton className="h-7 w-24" />
        <Skeleton className="h-4 w-72" />
      </div>

      {/* Toolbar skeleton */}
      <div className="flex items-center justify-between gap-3">
        <div className="flex gap-2">
          <Skeleton className="h-9 w-64 rounded-md" />
          <Skeleton className="h-9 w-40 rounded-md" />
        </div>
        <Skeleton className="h-9 w-28 rounded-md" />
      </div>

      {/* Table skeleton */}
      <div className="rounded-xl border border-border bg-card">
        <Table>
          <TableHeader>
            <TableRow>
              {["Name", "Email", "Role", "Department", "Status", "Actions"].map((h) => (
                <TableHead key={h}>{h}</TableHead>
              ))}
            </TableRow>
          </TableHeader>
          <TableBody>
            <TableSkeleton rows={10} columns={6} />
          </TableBody>
        </Table>
      </div>
    </div>
  );
}
