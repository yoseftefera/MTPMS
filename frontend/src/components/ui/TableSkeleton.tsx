/**
 * TableSkeleton — reusable loading skeleton for table pages.
 *
 * Renders N animated skeleton rows inside a <TableBody> so it can be
 * dropped directly inside any ShadCN <Table> without extra wrappers.
 *
 * Props:
 *   rows    — number of skeleton rows to render (default: 8)
 *   columns — number of skeleton cells per row (default: 6)
 *
 * Usage inside a table:
 *   <TableBody>
 *     {isLoading ? <TableSkeleton rows={10} columns={5} /> : <DataRows />}
 *   </TableBody>
 *
 * Validates: Requirements 22.5
 */

import { Skeleton } from "@/components/ui/skeleton";
import { TableRow, TableCell } from "@/components/ui/table";

interface TableSkeletonProps {
  rows?: number;
  columns?: number;
}

export function TableSkeleton({ rows = 8, columns = 6 }: TableSkeletonProps) {
  return (
    <>
      {Array.from({ length: rows }).map((_, rowIndex) => (
        <TableRow key={rowIndex} aria-hidden="true">
          {Array.from({ length: columns }).map((_, colIndex) => (
            <TableCell key={colIndex}>
              {/* Vary widths slightly so the skeleton looks more natural */}
              <Skeleton
                className={`h-4 ${
                  colIndex === 0
                    ? "w-28"
                    : colIndex === columns - 1
                      ? "w-12 mx-auto"
                      : colIndex % 3 === 0
                        ? "w-20"
                        : "w-24"
                }`}
              />
            </TableCell>
          ))}
        </TableRow>
      ))}
    </>
  );
}
