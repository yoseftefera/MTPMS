"use client"

/**
 * Inventory list page.
 *
 * Features:
 * - Alert banner: count of items below reorder threshold
 * - Paginated table: item code, item name, category, warehouse, current stock,
 *   reorder threshold, UoM
 * - Stock level badge: red "Low Stock" when current_stock < reorder_threshold,
 *   green "In Stock" otherwise
 * - Filters: item_code search, below_reorder toggle
 * - Loading skeleton, error state with retry
 *
 * Validates: Requirements 12.8, 22.6
 */

import { useState } from "react"
import { RefreshCw, Search, AlertTriangle, Package } from "lucide-react"
import { motion } from "framer-motion"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Skeleton } from "@/components/ui/skeleton"
import { Alert, AlertDescription } from "@/components/ui/alert"
import {
  Table,
  TableHeader,
  TableBody,
  TableRow,
  TableHead,
  TableCell,
} from "@/components/ui/table"
import { useInventory } from "@/hooks/useGoodsReceipts"
import type { InventoryFilters, InventoryItem } from "@/types/goodsReceipt"

// ─── Skeleton rows ────────────────────────────────────────────────────────────

function SkeletonRows() {
  return (
    <>
      {Array.from({ length: 10 }).map((_, i) => (
        <TableRow key={i}>
          {Array.from({ length: 8 }).map((_, j) => (
            <TableCell key={j}>
              <Skeleton className="h-4 w-20" />
            </TableCell>
          ))}
        </TableRow>
      ))}
    </>
  )
}

// ─── Stock level badge ────────────────────────────────────────────────────────

function StockLevelBadge({ item }: { item: InventoryItem }) {
  const current = parseFloat(item.current_stock)
  const threshold = parseFloat(item.reorder_threshold)
  const isLow = !isNaN(current) && !isNaN(threshold) && current < threshold

  if (isLow) {
    return (
      <span
        className="inline-flex items-center gap-1 rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-800 dark:bg-red-900/30 dark:text-red-300"
        aria-label="Low stock alert"
      >
        <AlertTriangle className="size-3" aria-hidden="true" />
        Low Stock
      </span>
    )
  }

  return (
    <span
      className="inline-flex items-center rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-800 dark:bg-green-900/30 dark:text-green-300"
      aria-label="In stock"
    >
      In Stock
    </span>
  )
}

// ─── Framer Motion variants ───────────────────────────────────────────────────

const fadeIn = {
  hidden: { opacity: 0, y: 8 },
  visible: { opacity: 1, y: 0, transition: { duration: 0.25, ease: "easeOut" as const } },
}

// ─── Page ─────────────────────────────────────────────────────────────────────

export default function InventoryPage() {
  const [itemCodeSearch, setItemCodeSearch] = useState("")
  const [belowReorderOnly, setBelowReorderOnly] = useState(false)
  const [page, setPage] = useState(1)

  const filters: InventoryFilters = {
    ...(itemCodeSearch && { item_code: itemCodeSearch }),
    ...(belowReorderOnly && { below_reorder: true }),
    page,
    per_page: 15,
  }

  const { data, isLoading, isError, refetch } = useInventory(filters)

  // Also fetch a count of low-stock items for the banner
  const { data: lowStockData } = useInventory({ below_reorder: true, per_page: 1 })
  const lowStockCount = lowStockData?.meta?.total ?? 0

  const items: InventoryItem[] = data?.data ?? []
  const meta = data?.meta

  function handleSearchChange(e: React.ChangeEvent<HTMLInputElement>) {
    setItemCodeSearch(e.target.value)
    setPage(1)
  }

  function handleBelowReorderToggle() {
    setBelowReorderOnly((prev) => !prev)
    setPage(1)
  }

  return (
    <motion.div
      className="space-y-6"
      initial="hidden"
      animate="visible"
      variants={fadeIn}
    >
      {/* Page header */}
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">Inventory</h1>
        <p className="mt-1 text-sm text-muted-foreground">
          Real-time stock levels per item and warehouse.
        </p>
      </div>

      {/* Low-stock alert banner */}
      {lowStockCount > 0 && (
        <Alert
          className="border-red-200 bg-red-50 dark:bg-red-950/30"
          role="alert"
          aria-live="polite"
        >
          <AlertTriangle
            className="size-4 text-red-600 dark:text-red-400"
            aria-hidden="true"
          />
          <AlertDescription className="text-red-700 dark:text-red-300">
            <strong>{lowStockCount} item{lowStockCount !== 1 ? "s" : ""}</strong> below
            reorder threshold.{" "}
            {!belowReorderOnly && (
              <button
                className="underline underline-offset-2 hover:no-underline"
                onClick={() => { setBelowReorderOnly(true); setPage(1) }}
              >
                Show low-stock items only
              </button>
            )}
          </AlertDescription>
        </Alert>
      )}

      {/* Toolbar */}
      <div className="flex flex-wrap items-center gap-3">
        {/* Item code search */}
        <div className="relative min-w-[200px] flex-1 sm:max-w-xs">
          <Search
            className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
            aria-hidden="true"
          />
          <Input
            placeholder="Search item code…"
            value={itemCodeSearch}
            onChange={handleSearchChange}
            className="pl-9"
            aria-label="Search by item code"
          />
        </div>

        {/* Below reorder toggle */}
        <label className="flex cursor-pointer items-center gap-2 text-sm">
          <input
            type="checkbox"
            checked={belowReorderOnly}
            onChange={handleBelowReorderToggle}
            className="h-4 w-4 rounded border-border"
            aria-label="Show only items below reorder threshold"
          />
          <span>Below reorder threshold only</span>
        </label>

        {/* Refresh */}
        <Button
          variant="outline"
          size="sm"
          onClick={() => refetch()}
          className="ml-auto"
          aria-label="Refresh inventory"
        >
          <RefreshCw className="size-4" aria-hidden="true" />
          Refresh
        </Button>
      </div>

      {/* Table */}
      <div className="rounded-xl border border-border bg-card">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Item Code</TableHead>
              <TableHead>Item Name</TableHead>
              <TableHead>Category</TableHead>
              <TableHead>Warehouse</TableHead>
              <TableHead className="text-right">Current Stock</TableHead>
              <TableHead className="text-right">Reorder Threshold</TableHead>
              <TableHead>UoM</TableHead>
              <TableHead>Stock Level</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {isLoading && <SkeletonRows />}

            {isError && (
              <TableRow>
                <TableCell
                  colSpan={8}
                  className="py-12 text-center text-muted-foreground"
                >
                  <p className="mb-3 text-sm">Failed to load inventory.</p>
                  <Button variant="outline" size="sm" onClick={() => refetch()}>
                    <RefreshCw className="size-3.5" aria-hidden="true" />
                    Retry
                  </Button>
                </TableCell>
              </TableRow>
            )}

            {!isLoading && !isError && items.length === 0 && (
              <TableRow>
                <TableCell
                  colSpan={8}
                  className="py-12 text-center text-muted-foreground"
                >
                  <Package className="mx-auto mb-2 size-8 opacity-40" aria-hidden="true" />
                  <p className="text-sm">
                    {belowReorderOnly
                      ? "No items below reorder threshold."
                      : "No inventory items found."}
                  </p>
                </TableCell>
              </TableRow>
            )}

            {!isLoading &&
              !isError &&
              items.map((item) => {
                const current = parseFloat(item.current_stock)
                const threshold = parseFloat(item.reorder_threshold)
                const isLow = !isNaN(current) && !isNaN(threshold) && current < threshold

                return (
                  <TableRow
                    key={item.id}
                    className={isLow ? "bg-red-50/30 dark:bg-red-950/10" : undefined}
                  >
                    <TableCell className="font-mono text-sm font-medium">
                      {item.item_code}
                    </TableCell>
                    <TableCell className="text-sm font-medium">
                      {item.item_name}
                    </TableCell>
                    <TableCell className="text-sm text-muted-foreground">
                      {item.category}
                    </TableCell>
                    <TableCell className="text-sm text-muted-foreground">
                      {item.warehouse?.name ?? item.warehouse_id}
                    </TableCell>
                    <TableCell className="text-right tabular-nums text-sm font-semibold">
                      <span className={isLow ? "text-red-700 dark:text-red-400" : undefined}>
                        {isNaN(current) ? item.current_stock : current.toLocaleString()}
                      </span>
                    </TableCell>
                    <TableCell className="text-right tabular-nums text-sm text-muted-foreground">
                      {isNaN(threshold) ? item.reorder_threshold : threshold.toLocaleString()}
                    </TableCell>
                    <TableCell className="text-sm text-muted-foreground">
                      {item.unit_of_measure}
                    </TableCell>
                    <TableCell>
                      <StockLevelBadge item={item} />
                    </TableCell>
                  </TableRow>
                )
              })}
          </TableBody>
        </Table>
      </div>

      {/* Pagination */}
      {meta && meta.last_page > 1 && (
        <div className="flex items-center justify-between text-sm text-muted-foreground">
          <span>
            Showing {meta.from ?? 0}–{meta.to ?? 0} of {meta.total} items
          </span>
          <div className="flex items-center gap-2">
            <Button
              variant="outline"
              size="sm"
              disabled={page <= 1}
              onClick={() => setPage((p) => Math.max(1, p - 1))}
              aria-label="Previous page"
            >
              Previous
            </Button>
            <span className="px-2">
              Page {meta.current_page} of {meta.last_page}
            </span>
            <Button
              variant="outline"
              size="sm"
              disabled={page >= meta.last_page}
              onClick={() => setPage((p) => p + 1)}
              aria-label="Next page"
            >
              Next
            </Button>
          </div>
        </div>
      )}
    </motion.div>
  )
}
