/**
 * Streaming loading UI for the Inventory page.
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

export default function InventoryLoading() {
  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="space-y-1.5">
        <Skeleton className="h-7 w-28" />
        <Skeleton className="h-4 w-56" />
      </div>

      {/* Toolbar skeleton */}
      <div className="flex flex-wrap items-center gap-3">
        <Skeleton className="h-9 w-56 rounded-md" />
        <Skeleton className="h-4 w-56 rounded-sm" />
        <Skeleton className="h-9 w-24 rounded-md ml-auto" />
      </div>

      {/* Table */}
      <div className="rounded-xl border border-border bg-card">
        <Table>
          <TableHeader>
            <TableRow>
              {["Item Code", "Item Name", "Category", "Warehouse", "Current Stock", "Reorder Threshold", "UoM", "Stock Level"].map((h) => (
                <TableHead key={h}>{h}</TableHead>
              ))}
            </TableRow>
          </TableHeader>
          <TableBody>
            <TableSkeleton rows={10} columns={8} />
          </TableBody>
        </Table>
      </div>
    </div>
  );
}
