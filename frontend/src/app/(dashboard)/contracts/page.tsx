"use client"

/**
 * Contract list page.
 *
 * Features:
 * - Paginated ShadCN Table: contract number (monospace), title, supplier,
 *   total value, consumption %, status badge, end date
 * - Status filter dropdown (all, draft, active, terminated, expired)
 * - "Create Contract" button (Procurement_Officer / Tenant_Admin only)
 * - Row click → navigate to detail page
 * - Loading skeletons, error state with retry
 *
 * Validates: Requirements 11.1, 22.6
 */

import { useState } from 'react';
import { useRouter } from 'next/navigation';
import { Plus, RefreshCw } from 'lucide-react';
import { motion } from 'framer-motion';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import {
  Table,
  TableHeader,
  TableBody,
  TableRow,
  TableHead,
  TableCell,
} from '@/components/ui/table';
import { ContractStatusBadge } from '@/components/contracts/ContractStatusBadge';
import { CreateContractForm } from '@/components/contracts/CreateContractForm';
import { useContracts } from '@/hooks/useContracts';
import { useSuppliers } from '@/hooks/useSuppliers';
import { useAuthStore } from '@/store/authStore';
import { formatCurrency, formatPercent } from '@/lib/utils';
import type { ContractFilters, ContractFilterStatus, ContractDetail } from '@/types/contract';

// ─── Constants ────────────────────────────────────────────────────────────────

const CAN_CREATE_ROLES = ['Procurement_Officer', 'Tenant_Admin'];

const STATUS_OPTIONS: { value: ContractFilterStatus; label: string }[] = [
  { value: '',            label: 'All Statuses'  },
  { value: 'draft',       label: 'Draft'         },
  { value: 'active',      label: 'Active'        },
  { value: 'terminated',  label: 'Terminated'    },
  { value: 'expired',     label: 'Expired'       },
];

// ─── Skeleton rows ────────────────────────────────────────────────────────────

function SkeletonRows() {
  return (
    <>
      {Array.from({ length: 8 }).map((_, i) => (
        <TableRow key={i}>
          {Array.from({ length: 7 }).map((_, j) => (
            <TableCell key={j}>
              <Skeleton className="h-4 w-24" />
            </TableCell>
          ))}
        </TableRow>
      ))}
    </>
  );
}

// ─── Framer Motion variants ───────────────────────────────────────────────────

const fadeIn = {
  hidden: { opacity: 0, y: 8 },
  visible: { opacity: 1, y: 0, transition: { duration: 0.25, ease: 'easeOut' as const } },
};

// ─── Page ─────────────────────────────────────────────────────────────────────

export default function ContractsPage() {
  const router = useRouter();
  const role = useAuthStore((s) => s.role);
  const canCreate = role !== null && CAN_CREATE_ROLES.includes(role);

  const [createOpen, setCreateOpen] = useState(false);
  const [status, setStatus] = useState<ContractFilterStatus>('');
  const [page, setPage] = useState(1);

  const filters: ContractFilters = {
    ...(status && { status }),
    page,
    per_page: 15,
  };

  const { data, isLoading, isError, refetch } = useContracts(filters);

  // Fetch active suppliers for the create form
  const { data: suppliersData } = useSuppliers({ status: 'active', per_page: 200 });
  const activeSuppliers = suppliersData?.data ?? [];

  const contracts: ContractDetail[] = data?.data ?? [];
  const meta = data?.meta;

  function handleStatusChange(val: string) {
    setStatus(val === 'all' ? '' : (val as ContractFilterStatus));
    setPage(1);
  }

  function handleRowClick(contract: ContractDetail) {
    router.push(`/contracts/${contract.id}`);
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
        <h1 className="text-2xl font-semibold tracking-tight">Contracts</h1>
        <p className="mt-1 text-sm text-muted-foreground">
          Manage and track supplier contracts throughout their lifecycle.
        </p>
      </div>

      {/* Toolbar */}
      <div className="flex flex-wrap items-center justify-between gap-3">
        {/* Status filter */}
        <Select value={status || 'all'} onValueChange={handleStatusChange}>
          <SelectTrigger className="w-48" aria-label="Filter by status">
            <SelectValue placeholder="All Statuses" />
          </SelectTrigger>
          <SelectContent>
            {STATUS_OPTIONS.map((opt) => (
              <SelectItem key={opt.value || 'all'} value={opt.value || 'all'}>
                {opt.label}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>

        {/* Create button */}
        {canCreate && (
          <Button
            onClick={() => setCreateOpen(true)}
            aria-label="Create new contract"
          >
            <Plus className="size-4" aria-hidden="true" />
            Create Contract
          </Button>
        )}
      </div>

      {/* Table */}
      <div className="rounded-xl border border-border bg-card">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Contract #</TableHead>
              <TableHead>Title</TableHead>
              <TableHead>Supplier</TableHead>
              <TableHead className="text-right">Total Value</TableHead>
              <TableHead className="text-right">Consumed</TableHead>
              <TableHead>Status</TableHead>
              <TableHead>End Date</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {isLoading && <SkeletonRows />}

            {isError && (
              <TableRow>
                <TableCell
                  colSpan={7}
                  className="py-12 text-center text-muted-foreground"
                >
                  <p className="mb-3 text-sm">Failed to load contracts.</p>
                  <Button variant="outline" size="sm" onClick={() => refetch()}>
                    <RefreshCw className="size-3.5" aria-hidden="true" />
                    Retry
                  </Button>
                </TableCell>
              </TableRow>
            )}

            {!isLoading && !isError && contracts.length === 0 && (
              <TableRow>
                <TableCell
                  colSpan={7}
                  className="py-12 text-center text-muted-foreground"
                >
                  <p className="text-sm">No contracts found.</p>
                  {canCreate && (
                    <button
                      className="mt-1 text-sm text-primary underline-offset-2 hover:underline"
                      onClick={() => setCreateOpen(true)}
                    >
                      Create your first contract.
                    </button>
                  )}
                </TableCell>
              </TableRow>
            )}

            {!isLoading &&
              !isError &&
              contracts.map((contract) => (
                <TableRow
                  key={contract.id}
                  className="cursor-pointer hover:bg-muted/50 transition-colors"
                  onClick={() => handleRowClick(contract)}
                  tabIndex={0}
                  role="button"
                  aria-label={`View contract ${contract.contract_number}`}
                  onKeyDown={(e) => {
                    if (e.key === 'Enter' || e.key === ' ') {
                      e.preventDefault();
                      handleRowClick(contract);
                    }
                  }}
                >
                  <TableCell className="font-mono text-sm font-medium">
                    {contract.contract_number}
                  </TableCell>
                  <TableCell className="max-w-[200px] truncate text-sm">
                    {contract.title}
                  </TableCell>
                  <TableCell className="text-sm text-muted-foreground">
                    {contract.supplier?.organization_name ?? '—'}
                  </TableCell>
                  <TableCell className="text-right tabular-nums font-medium">
                    {formatCurrency(contract.total_value, contract.currency)}
                  </TableCell>
                  <TableCell className="text-right tabular-nums text-sm">
                    <span
                      className={
                        contract.consumption_percentage >= 80
                          ? 'text-amber-600 dark:text-amber-400 font-medium'
                          : 'text-muted-foreground'
                      }
                    >
                      {formatPercent(contract.consumption_percentage)}
                    </span>
                  </TableCell>
                  <TableCell>
                    <ContractStatusBadge status={contract.status} />
                  </TableCell>
                  <TableCell className="text-sm text-muted-foreground">
                    {new Date(contract.end_date).toLocaleDateString()}
                  </TableCell>
                </TableRow>
              ))}
          </TableBody>
        </Table>
      </div>

      {/* Pagination */}
      {meta && meta.last_page > 1 && (
        <div className="flex items-center justify-between text-sm text-muted-foreground">
          <span>
            Showing {meta.from ?? 0}–{meta.to ?? 0} of {meta.total} contracts
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

      {/* Create contract dialog */}
      <CreateContractForm
        open={createOpen}
        onOpenChange={setCreateOpen}
        suppliers={activeSuppliers}
        onSuccess={() => refetch()}
      />
    </motion.div>
  );
}
