"use client"

/**
 * Suspend Tenant Confirmation Dialog.
 *
 * Confirms before suspending an active tenant. On suspend, all authentication
 * and API requests for that tenant's users are denied while data is preserved.
 *
 * Validates: Requirements 1.5, 1.6, 1.10
 */

import { PauseCircle } from "lucide-react"
import { Button } from "@/components/ui/button"
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from "@/components/ui/dialog"
import { Alert, AlertDescription } from "@/components/ui/alert"
import { useSuspendTenant } from "@/hooks/useTenants"
import type { Tenant } from "@/types/models.types"

interface SuspendTenantDialogProps {
  tenant: Tenant
  open: boolean
  onOpenChange: (open: boolean) => void
  onSuccess?: () => void
}

export function SuspendTenantDialog({
  tenant,
  open,
  onOpenChange,
  onSuccess,
}: SuspendTenantDialogProps) {
  const suspendTenant = useSuspendTenant()

  const handleSuspend = async () => {
    await suspendTenant.mutateAsync(tenant.id, {
      onSuccess: () => {
        onOpenChange(false)
        onSuccess?.()
      },
    })
  }

  const serverError = suspendTenant.error as
    | { response?: { data?: { message?: string } } }
    | null

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-sm">
        <DialogHeader>
          <div className="mb-2 flex h-10 w-10 items-center justify-center rounded-full bg-amber-100 dark:bg-amber-900/30">
            <PauseCircle className="size-5 text-amber-700 dark:text-amber-400" />
          </div>
          <DialogTitle>Suspend Tenant</DialogTitle>
          <DialogDescription>
            Are you sure you want to suspend{" "}
            <strong className="text-foreground">{tenant.name}</strong>{" "}
            (<span className="font-mono text-xs">{tenant.tenant_code}</span>)?
            All users in this tenant will lose access immediately. All data will
            be preserved and the tenant can be reactivated.
          </DialogDescription>
        </DialogHeader>

        {serverError?.response?.data?.message && (
          <Alert variant="destructive">
            <AlertDescription>
              {serverError.response.data.message}
            </AlertDescription>
          </Alert>
        )}

        <DialogFooter>
          <Button
            variant="outline"
            onClick={() => {
              suspendTenant.reset()
              onOpenChange(false)
            }}
            disabled={suspendTenant.isPending}
          >
            Cancel
          </Button>
          <Button
            variant="warning"
            onClick={handleSuspend}
            disabled={suspendTenant.isPending}
            className="bg-amber-600 text-white hover:bg-amber-700 dark:bg-amber-700 dark:hover:bg-amber-600"
          >
            {suspendTenant.isPending ? "Suspending…" : "Suspend Tenant"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
