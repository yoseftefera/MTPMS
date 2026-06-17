"use client"

/**
 * Workflows DataTable component.
 *
 * Features:
 * - Paginated list of approval workflows
 * - Columns: Name, Document Type, Levels, Status, Actions
 * - "Create Workflow" button opens CreateWorkflowDialog
 * - Row actions: Edit, Deactivate (toggle is_active)
 *
 * Validates: Requirements 6.8, 22.5
 */

import { useState, useCallback, useTransition } from "react"
import { Search, Plus, Pencil, ToggleLeft, RefreshCw } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Badge } from "@/components/ui/badge"
import { Skeleton } from "@/components/ui/skeleton"
import {
  Table,
  TableHeader,
  TableBody,
  TableRow,
  TableHead,
  TableCell,
} from "@/components/ui/table"
import { CreateWorkflowDialog } from "./CreateWorkflowDialog"
import { EditWorkflowDialog } from "./EditWorkflowDialog"
import {
  useApprovalWorkflows,
  useUpdateApprovalWorkflow,
} from "@/hooks/useApprovalWorkflows"
import {
  DOCUMENT_TYPE_LABELS,
  type DocumentType,
} from "@/lib/validations/approvalWorkflows"
import type { ApprovalWorkflow } from "@/types/models.types"

// ─── Helpers ──────────────────────────────────────────────────────────────────

function StatusBadge({ isActive }: { isActive: boolean }) {
  return isActive ? (
    <Badge variant="success">Active</Badge>
  ) : (
    <Badge variant="destructive">Inactive</Badge>
  )
}

function RowActions({
  workflow,
  onEdit,
}: {
  workflow: ApprovalWorkflow
  onEdit: (w: ApprovalWorkflow) => void
}) {
  const update = useUpdateApprovalWorkflow(workflow.id)

  const handleToggleActive = () => {
    update.mutate({ is_active: !workflow.is_active })
  }

  return (
    <div className="flex items-center justify-end gap-1">
      <Button
        variant="ghost"
        size="icon-sm"
        aria-label={`Edit workflow ${workflow.name}`}
        onClick={() => onEdit(workflow)}
      >
        <Pencil className="size-4" />
      </Button>
      <Button
        variant="ghost"
        size="icon-sm"
        aria-label={workflow.is_active ? `Deactivate ${workflow.name}` : `Activate ${workflow.name}`}
        onClick={handleToggleActive}
        disabled={update.isPending}
        className={
          workflow.is_active
            ? "text-destructive hover:bg-destructive/10 hover:text-destructive"
            : "text-green-700 hover:bg-green-50 dark:text-green-400 dark:hover:bg-green-900/20"
        }
      >
        <ToggleLeft className="size-4" />
      </Button>
    </div>
  )
}

function SkeletonRows() {
  return (
    <>
      {Array.from({ length: 6 }).map((_, i) => (
        <TableRow key={i}>
          <TableCell><Skeleton className="h-4 w-40" /></TableCell>
          <TableCell><Skeleton className="h-5 w-28 rounded-full" /></TableCell>
          <TableCell><Skeleton className="h-4 w-8" /></TableCell>
          <TableCell><Skeleton className="h-5 w-16 rounded-full" /></TableCell>
          <TableCell />
        </TableRow>
      ))}
    </>
  )
}

// ─── Main component ───────────────────────────────────────────────────────────

export function WorkflowsDataTable() {
  const [search, setSearch] = useState("")
  const [debouncedSearch, setDebouncedSearch] = useState("")
  const [page, setPage] = useState(1)

  const [, startTransition] = useTransition()

  const [createOpen, setCreateOpen] = useState(false)
  const [editTarget, setEditTarget] = useState<ApprovalWorkflow | null>(null)

  const handleSearchChange = useCallback((value: string) => {
    setSearch(value)
    const t = setTimeout(() => {
      startTransition(() => {
        setDebouncedSearch(value)
        setPage(1)
      })
    }, 400)
    return () => clearTimeout(t)
  }, [])

  const queryParams = {
    page,
    per_page: 15,
    search: debouncedSearch || undefined,
    sort_by: "created_at",
    sort_dir: "desc" as const,
  }

  const { data, isLoading, isError, refetch } = useApprovalWorkflows(queryParams)

  const workflows = data?.data ?? []
  const meta = data?.meta

  return (
    <div className="space-y-4">
      {/* Toolbar */}
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div className="relative w-full sm:max-w-xs">
          <Search className="absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
          <Input
            placeholder="Search workflows…"
            value={search}
            onChange={(e) => handleSearchChange(e.target.value)}
            className="pl-8"
            aria-label="Search workflows"
          />
        </div>

        <Button onClick={() => setCreateOpen(true)} className="shrink-0">
          <Plus className="size-4" />
          Create Workflow
        </Button>
      </div>

      {/* Table */}
      <div className="rounded-xl border border-border bg-card">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Name</TableHead>
              <TableHead>Document Type</TableHead>
              <TableHead>Levels</TableHead>
              <TableHead>Status</TableHead>
              <TableHead className="w-20" />
            </TableRow>
          </TableHeader>
          <TableBody>
            {isLoading && <SkeletonRows />}

            {isError && (
              <TableRow>
                <TableCell colSpan={5} className="py-10 text-center text-muted-foreground">
                  <p className="mb-2">Failed to load workflows.</p>
                  <Button variant="outline" size="sm" onClick={() => refetch()}>
                    <RefreshCw className="size-3.5" />
                    Retry
                  </Button>
                </TableCell>
              </TableRow>
            )}

            {!isLoading && !isError && workflows.length === 0 && (
              <TableRow>
                <TableCell colSpan={5} className="py-10 text-center text-muted-foreground">
                  {debouncedSearch
                    ? "No workflows match the search."
                    : "No approval workflows yet. Create the first one."}
                </TableCell>
              </TableRow>
            )}

            {!isLoading &&
              !isError &&
              workflows.map((workflow) => (
                <TableRow key={workflow.id}>
                  <TableCell className="text-sm font-medium">{workflow.name}</TableCell>
                  <TableCell>
                    <Badge variant="secondary">
                      {DOCUMENT_TYPE_LABELS[workflow.document_type as DocumentType] ??
                        workflow.document_type}
                    </Badge>
                  </TableCell>
                  <TableCell className="text-sm text-muted-foreground">
                    {workflow.levels?.length ?? 0}
                  </TableCell>
                  <TableCell>
                    <StatusBadge isActive={workflow.is_active} />
                  </TableCell>
                  <TableCell>
                    <RowActions workflow={workflow} onEdit={setEditTarget} />
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
            Showing {meta.from ?? 0}–{meta.to ?? 0} of {meta.total} workflows
          </span>
          <div className="flex items-center gap-2">
            <Button
              variant="outline"
              size="sm"
              onClick={() => setPage((p) => Math.max(1, p - 1))}
              disabled={page === 1 || isLoading}
            >
              Previous
            </Button>
            <span className="px-1 text-xs">
              Page {meta.current_page} of {meta.last_page}
            </span>
            <Button
              variant="outline"
              size="sm"
              onClick={() => setPage((p) => Math.min(meta.last_page, p + 1))}
              disabled={page === meta.last_page || isLoading}
            >
              Next
            </Button>
          </div>
        </div>
      )}

      {/* Dialogs */}
      <CreateWorkflowDialog
        open={createOpen}
        onOpenChange={setCreateOpen}
        onSuccess={() => refetch()}
      />

      {editTarget && (
        <EditWorkflowDialog
          workflow={editTarget}
          open={!!editTarget}
          onOpenChange={(open) => !open && setEditTarget(null)}
          onSuccess={() => refetch()}
        />
      )}
    </div>
  )
}
