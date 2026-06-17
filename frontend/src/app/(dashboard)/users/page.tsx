/**
 * Users management page.
 *
 * Accessible at /users (within the dashboard route group).
 * Tenant_Admin can view, create, edit, and deactivate users here.
 *
 * Validates: Requirements 4.1, 4.6, 22.6
 */

import { UsersDataTable } from "@/components/users/UsersDataTable"

export const metadata = {
  title: "Users — PMP",
  description: "Manage user accounts and role assignments for your organization.",
}

export default function UsersPage() {
  return (
    <div className="space-y-6">
      {/* Page header */}
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">Users</h1>
        <p className="mt-1 text-sm text-muted-foreground">
          Manage user accounts and role assignments for your organization.
        </p>
      </div>

      {/* Data table (client component) */}
      <UsersDataTable />
    </div>
  )
}
