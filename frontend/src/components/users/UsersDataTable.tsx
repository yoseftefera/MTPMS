"use client"

/**
 * Users DataTable component.
 *
 * Features:
 * - Paginated list of users with server-side pagination
 * - Search by name/email (debounced)
 * - Filter by role and status
 * - Columns: name, email, role badge, status badge, department, joined date, actions
 * - Actions: Edit, Deactivate/Reactivate per row
 *
 * Validates: Requirements 4.1, 4.6, 22.6
 */

import { useState, useCallback, useTransition } from "react"
import { Search, Plus, MoreHorizontal, Pencil, UserX, UserCheck, RefreshCw } from "lucide-react"
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
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { CreateUserDialog } from "./CreateUserDialog"
import { EditUserDialog } from "./EditUserDialog"
import { DeactivateUserDialog } from "./DeactivateUserDialog"
import { useUsers, useReactivateUser } from "@/hooks/useUsers"
import { ROLE_LABELS, type TenantRole } from "@/lib/validations/users"
import type { User } from "@/types/models.types"

// ─── Helpers ──────────────────────────────────────────────────────────────────

function StatusBadge({ status }: { status: User["status"] }) {
  const map: Record<User["status"], { label: string; variant: "success" | "destructive" | "locked" }> = {
    active: { label: "Active", variant: "success" },
    inactive: { label: "Inactive", variant: "destructive" },
    locked: { label: "Locked", variant: "locked" },
  }
  const { label, variant } = map[status]
  return <Badge variant={variant}>{label}</Badge>
}

function RoleBadge({ roles }: { roles: string[] }) {
  const role = roles?.[0] as TenantRole | undefined
  const label = role ? (ROLE_LABELS[role] ?? role) : "—"
  return (
    <Badge variant="secondary" className="font-normal">
      {label}
    </Badge>
  )
}

function RowActions({
  user,
  onEdit,
  onDeactivate,
}: {
  user: User
  onEdit: (user: User) => void
  onDeactivate: (user: User) => void
}) {
  const reactivate = useReactivateUser()
  const [menuOpen, setMenuOpen] = useState(false)

  return (
    <div className="relative flex justify-end">
      <Button
        variant="ghost"
        size="icon-sm"
        aria-label={`Actions for ${user.name}`}
        aria-haspopup="menu"
        aria-expanded={menuOpen}
        onClick={() => setMenuOpen((v) => !v)}
      >
        <MoreHorizontal className="size-4" />
      </Button>

      {menuOpen && (
        <>
          {/* backdrop */}
          <div
            className="fixed inset-0 z-10"
            onClick={() => setMenuOpen(false)}
          />
          <div
            role="menu"
            className="absolute right-0 top-full z-20 mt-1 w-44 rounded-lg border border-border bg-popover py-1 text-sm shadow-md"
          >
            <button
              role="menuitem"
              className="flex w-full items-center gap-2 px-3 py-2 text-left transition-colors hover:bg-muted"
              onClick={() => {
                setMenuOpen(false)
                onEdit(user)
              }}
            >
              <Pencil className="size-3.5 text-muted-foreground" />
              Edit User
            </button>

            {user.status === "active" ? (
              <button
                role="menuitem"
                className="flex w-full items-center gap-2 px-3 py-2 text-left text-destructive transition-colors hover:bg-destructive/10"
                onClick={() => {
                  setMenuOpen(false)
                  onDeactivate(user)
                }}
              >
                <UserX className="size-3.5" />
                Deactivate
              </button>
            ) : (
              <button
                role="menuitem"
                className="flex w-full items-center gap-2 px-3 py-2 text-left text-green-700 transition-colors hover:bg-green-50 dark:text-green-400 dark:hover:bg-green-900/20"
                disabled={reactivate.isPending}
                onClick={() => {
                  setMenuOpen(false)
                  reactivate.mutate(user.id)
                }}
              >
                <UserCheck className="size-3.5" />
                {reactivate.isPending ? "Reactivating…" : "Reactivate"}
              </button>
            )}
          </div>
        </>
      )}
    </div>
  )
}

// ─── Skeleton rows ────────────────────────────────────────────────────────────

function SkeletonRows() {
  return (
    <>
      {Array.from({ length: 8 }).map((_, i) => (
        <TableRow key={i}>
          <TableCell>
            <div className="space-y-1.5">
              <Skeleton className="h-4 w-32" />
              <Skeleton className="h-3 w-44" />
            </div>
          </TableCell>
          <TableCell>
            <Skeleton className="h-5 w-24 rounded-full" />
          </TableCell>
          <TableCell>
            <Skeleton className="h-5 w-16 rounded-full" />
          </TableCell>
          <TableCell>
            <Skeleton className="h-4 w-24" />
          </TableCell>
          <TableCell>
            <Skeleton className="h-4 w-20" />
          </TableCell>
          <TableCell />
        </TableRow>
      ))}
    </>
  )
}

// ─── Main component ───────────────────────────────────────────────────────────

const ROLE_FILTER_OPTIONS = [
  { value: "all", label: "All Roles" },
  { value: "Tenant_Admin", label: "Tenant Admin" },
  { value: "Procurement_Officer", label: "Procurement Officer" },
  { value: "Finance_Officer", label: "Finance Officer" },
  { value: "Store_Manager", label: "Store Manager" },
  { value: "Committee_Member", label: "Committee Member" },
  { value: "Department_Staff", label: "Department Staff" },
  { value: "Supplier", label: "Supplier" },
]

const STATUS_FILTER_OPTIONS = [
  { value: "all", label: "All Statuses" },
  { value: "active", label: "Active" },
  { value: "inactive", label: "Inactive" },
  { value: "locked", label: "Locked" },
]

export function UsersDataTable() {
  const [search, setSearch] = useState("")
  const [debouncedSearch, setDebouncedSearch] = useState("")
  const [roleFilter, setRoleFilter] = useState("all")
  const [statusFilter, setStatusFilter] = useState("all")
  const [page, setPage] = useState(1)

  const [, startTransition] = useTransition()

  // Dialogs
  const [createOpen, setCreateOpen] = useState(false)
  const [editTarget, setEditTarget] = useState<User | null>(null)
  const [deactivateTarget, setDeactivateTarget] = useState<User | null>(null)

  // Debounce search
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
    role: roleFilter !== "all" ? roleFilter : undefined,
    status: statusFilter !== "all" ? (statusFilter as User["status"]) : undefined,
    sort_by: "created_at",
    sort_dir: "desc" as const,
  }

  const { data, isLoading, isError, refetch } = useUsers(queryParams)

  const users = data?.data ?? []
  const meta = data?.meta

  const formatDate = (iso: string) =>
    new Intl.DateTimeFormat("en-US", { month: "short", day: "numeric", year: "numeric" }).format(
      new Date(iso),
    )

  return (
    <div className="space-y-4">
      {/* Toolbar */}
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex flex-1 flex-col gap-2 sm:flex-row sm:items-center">
          {/* Search */}
          <div className="relative w-full sm:max-w-xs">
            <Search className="absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
            <Input
              placeholder="Search by name or email…"
              value={search}
              onChange={(e) => handleSearchChange(e.target.value)}
              className="pl-8"
              aria-label="Search users"
            />
          </div>

          {/* Role filter */}
          <div className="relative w-full sm:w-48">
            <Select
              value={roleFilter}
              onValueChange={(val) => {
                setRoleFilter(val)
                setPage(1)
              }}
            >
              <SelectTrigger aria-label="Filter by role">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {ROLE_FILTER_OPTIONS.map((opt) => (
                  <SelectItem key={opt.value} value={opt.value}>
                    {opt.label}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          {/* Status filter */}
          <div className="relative w-full sm:w-40">
            <Select
              value={statusFilter}
              onValueChange={(val) => {
                setStatusFilter(val)
                setPage(1)
              }}
            >
              <SelectTrigger aria-label="Filter by status">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {STATUS_FILTER_OPTIONS.map((opt) => (
                  <SelectItem key={opt.value} value={opt.value}>
                    {opt.label}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
        </div>

        <Button onClick={() => setCreateOpen(true)} className="shrink-0">
          <Plus className="size-4" />
          Add User
        </Button>
      </div>

      {/* Table */}
      <div className="rounded-xl border border-border bg-card">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>User</TableHead>
              <TableHead>Role</TableHead>
              <TableHead>Status</TableHead>
              <TableHead>Department</TableHead>
              <TableHead>Joined</TableHead>
              <TableHead className="w-12" />
            </TableRow>
          </TableHeader>
          <TableBody>
            {isLoading && <SkeletonRows />}

            {isError && (
              <TableRow>
                <TableCell colSpan={6} className="py-10 text-center text-muted-foreground">
                  <p className="mb-2">Failed to load users.</p>
                  <Button variant="outline" size="sm" onClick={() => refetch()}>
                    <RefreshCw className="size-3.5" />
                    Retry
                  </Button>
                </TableCell>
              </TableRow>
            )}

            {!isLoading && !isError && users.length === 0 && (
              <TableRow>
                <TableCell colSpan={6} className="py-10 text-center text-muted-foreground">
                  {debouncedSearch || roleFilter !== "all" || statusFilter !== "all"
                    ? "No users match the current filters."
                    : "No users yet. Create the first one."}
                </TableCell>
              </TableRow>
            )}

            {!isLoading &&
              !isError &&
              users.map((user) => (
                <TableRow key={user.id}>
                  <TableCell>
                    <div className="flex items-center gap-3">
                      {/* Avatar placeholder */}
                      <div
                        aria-hidden="true"
                        className="flex size-8 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-semibold text-primary"
                      >
                        {user.name
                          .split(" ")
                          .map((p) => p[0])
                          .slice(0, 2)
                          .join("")
                          .toUpperCase()}
                      </div>
                      <div>
                        <p className="text-sm font-medium leading-none">{user.name}</p>
                        <p className="mt-0.5 text-xs text-muted-foreground">{user.email}</p>
                      </div>
                    </div>
                  </TableCell>

                  <TableCell>
                    <RoleBadge roles={user.roles} />
                  </TableCell>

                  <TableCell>
                    <StatusBadge status={user.status} />
                  </TableCell>

                  <TableCell className="text-sm text-muted-foreground">
                    {user.department_id ?? "—"}
                  </TableCell>

                  <TableCell className="text-sm text-muted-foreground">
                    {formatDate(user.created_at)}
                  </TableCell>

                  <TableCell>
                    <RowActions
                      user={user}
                      onEdit={setEditTarget}
                      onDeactivate={setDeactivateTarget}
                    />
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
            Showing {meta.from ?? 0}–{meta.to ?? 0} of {meta.total} users
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
      <CreateUserDialog
        open={createOpen}
        onOpenChange={setCreateOpen}
        onSuccess={() => refetch()}
      />

      {editTarget && (
        <EditUserDialog
          user={editTarget}
          open={!!editTarget}
          onOpenChange={(open) => !open && setEditTarget(null)}
          onSuccess={() => refetch()}
        />
      )}

      {deactivateTarget && (
        <DeactivateUserDialog
          user={deactivateTarget}
          open={!!deactivateTarget}
          onOpenChange={(open) => !open && setDeactivateTarget(null)}
          onSuccess={() => refetch()}
        />
      )}
    </div>
  )
}
