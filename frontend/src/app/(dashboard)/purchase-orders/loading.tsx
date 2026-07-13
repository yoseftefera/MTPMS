/**
 * Streaming loading UI for the Purchase Orders list page.
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

export default function PurchaseOrdersLoading() {
  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="space-y-1.5">
        <Skeleton className="h-7 w-44" />
        <Skeleton className="h-4 w-72" />
      </div>

      {/* Toolbar skeleton */}
      <div className="flex flex-col gap-3">
        <div className="flex flex-wrap gap-2">
          <Skeleton className="h-9 w-56 rounded-md" />
          <Skeleton className="h-9 w-48 rounded-md" />
        </div>
        <div className="flex justify-end">
          <Skeleton className="h-9 w-28 rounded-md" />
        </div>
      </div>

      {/* Table */}
      <div className="rounded-xl border border-border bg-card">
        <Table>
          <TableHeader>
            <TableRow>
              {["PO Number", "Supplier", "Department", "Status", "Total Amount", "Delivery Date", "Created", ""].map((h) => (
                <TableHead key={h}>{h}</TableHead>
              ))}
            </TableRow>
          </TableHeader>
          <TableBody>
            <TableSkeleton rows={8} columns={8} />
          </TableBody>
        </Table>
      </div>
    </div>
  );
}
