"use client";

/**
 * Reusable report filter bar.
 *
 * Renders a responsive row of filter controls:
 *   - Date range (from / to)
 *   - Department selector  (optional)
 *   - Category input       (optional)
 *   - Status selector      (optional)
 *   - Supplier selector    (optional)
 *
 * Each filter is individually opt-in via the `show*` props so report pages
 * only render the controls relevant to their data.
 *
 * Validates: Requirements 16.10, 22.1
 */

import { useState } from "react";
import { CalendarIcon, FilterX } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import type { ReportFilters } from "@/types/reporting";

// ─── Types ────────────────────────────────────────────────────────────────────

interface FilterOption {
  value: string;
  label: string;
}

interface ReportFiltersProps {
  filters: ReportFilters;
  onChange: (updated: ReportFilters) => void;

  // Toggle which controls are shown
  showDateRange?: boolean;
  showDepartment?: boolean;
  showCategory?: boolean;
  showStatus?: boolean;
  showSupplier?: boolean;

  // Options for selectors
  departmentOptions?: FilterOption[];
  statusOptions?: FilterOption[];
  supplierOptions?: FilterOption[];
  categoryOptions?: FilterOption[];
}

// ─── Component ────────────────────────────────────────────────────────────────

export function ReportFiltersBar({
  filters,
  onChange,
  showDateRange = true,
  showDepartment = false,
  showCategory = false,
  showStatus = false,
  showSupplier = false,
  departmentOptions = [],
  statusOptions = [],
  supplierOptions = [],
  categoryOptions = [],
}: ReportFiltersProps) {
  const hasActiveFilters = !!(
    filters.date_from ||
    filters.date_to ||
    filters.department_id ||
    filters.category ||
    filters.status ||
    filters.supplier_id
  );

  function update(partial: Partial<ReportFilters>) {
    onChange({ ...filters, ...partial });
  }

  function clearAll() {
    onChange({});
  }

  return (
    <div
      className="flex flex-wrap items-end gap-3"
      role="search"
      aria-label="Report filters"
    >
      {/* Date From */}
      {showDateRange && (
        <div className="flex flex-col gap-1">
          <Label htmlFor="filter-date-from" className="text-xs text-muted-foreground">
            From
          </Label>
          <div className="relative">
            <CalendarIcon className="absolute left-2.5 top-2.5 size-3.5 text-muted-foreground pointer-events-none" />
            <Input
              id="filter-date-from"
              type="date"
              className="pl-8 w-36 h-9 text-sm"
              value={filters.date_from ?? ""}
              max={filters.date_to}
              onChange={(e) => update({ date_from: e.target.value || undefined })}
              aria-label="Filter from date"
            />
          </div>
        </div>
      )}

      {/* Date To */}
      {showDateRange && (
        <div className="flex flex-col gap-1">
          <Label htmlFor="filter-date-to" className="text-xs text-muted-foreground">
            To
          </Label>
          <div className="relative">
            <CalendarIcon className="absolute left-2.5 top-2.5 size-3.5 text-muted-foreground pointer-events-none" />
            <Input
              id="filter-date-to"
              type="date"
              className="pl-8 w-36 h-9 text-sm"
              value={filters.date_to ?? ""}
              min={filters.date_from}
              onChange={(e) => update({ date_to: e.target.value || undefined })}
              aria-label="Filter to date"
            />
          </div>
        </div>
      )}

      {/* Department */}
      {showDepartment && (
        <div className="flex flex-col gap-1">
          <Label className="text-xs text-muted-foreground">Department</Label>
          <Select
            value={filters.department_id ?? "all"}
            onValueChange={(v) =>
              update({ department_id: v === "all" ? undefined : v })
            }
          >
            <SelectTrigger className="w-44 h-9 text-sm" aria-label="Filter by department">
              <SelectValue placeholder="All departments" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All departments</SelectItem>
              {departmentOptions.map((opt) => (
                <SelectItem key={opt.value} value={opt.value}>
                  {opt.label}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
      )}

      {/* Category */}
      {showCategory && categoryOptions.length > 0 ? (
        <div className="flex flex-col gap-1">
          <Label className="text-xs text-muted-foreground">Category</Label>
          <Select
            value={filters.category ?? "all"}
            onValueChange={(v) =>
              update({ category: v === "all" ? undefined : v })
            }
          >
            <SelectTrigger className="w-40 h-9 text-sm" aria-label="Filter by category">
              <SelectValue placeholder="All categories" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All categories</SelectItem>
              {categoryOptions.map((opt) => (
                <SelectItem key={opt.value} value={opt.value}>
                  {opt.label}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
      ) : showCategory ? (
        <div className="flex flex-col gap-1">
          <Label htmlFor="filter-category" className="text-xs text-muted-foreground">
            Category
          </Label>
          <Input
            id="filter-category"
            className="w-36 h-9 text-sm"
            placeholder="Category…"
            value={filters.category ?? ""}
            onChange={(e) => update({ category: e.target.value || undefined })}
          />
        </div>
      ) : null}

      {/* Status */}
      {showStatus && (
        <div className="flex flex-col gap-1">
          <Label className="text-xs text-muted-foreground">Status</Label>
          <Select
            value={filters.status ?? "all"}
            onValueChange={(v) =>
              update({ status: v === "all" ? undefined : v })
            }
          >
            <SelectTrigger className="w-40 h-9 text-sm" aria-label="Filter by status">
              <SelectValue placeholder="All statuses" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All statuses</SelectItem>
              {statusOptions.map((opt) => (
                <SelectItem key={opt.value} value={opt.value}>
                  {opt.label}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
      )}

      {/* Supplier */}
      {showSupplier && (
        <div className="flex flex-col gap-1">
          <Label className="text-xs text-muted-foreground">Supplier</Label>
          <Select
            value={filters.supplier_id ?? "all"}
            onValueChange={(v) =>
              update({ supplier_id: v === "all" ? undefined : v })
            }
          >
            <SelectTrigger className="w-44 h-9 text-sm" aria-label="Filter by supplier">
              <SelectValue placeholder="All suppliers" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All suppliers</SelectItem>
              {supplierOptions.map((opt) => (
                <SelectItem key={opt.value} value={opt.value}>
                  {opt.label}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
      )}

      {/* Clear filters */}
      {hasActiveFilters && (
        <Button
          variant="ghost"
          size="sm"
          onClick={clearAll}
          className="h-9 gap-1.5 text-muted-foreground"
          aria-label="Clear all filters"
        >
          <FilterX className="size-3.5" />
          Clear
        </Button>
      )}
    </div>
  );
}
