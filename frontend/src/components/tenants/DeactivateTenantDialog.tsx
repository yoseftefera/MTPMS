"use client"

/**
 * Deactivate Tenant Confirmation Dialog.
 *
 * Confirms before permanently deactivating a tenant. This is an irreversible
 * action that should be used with caution.
 *
 * Validates: Requirements 1.6, 1.10
 */

import { AlertTriangle } from "lucide-react"
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
import { useDeactivateTenant } from "@/hooks/useTenants"
import type { Tenant } from "@/types/models.types"

interface DeactivateTenantDialogProps {
  tenant: Tenant
  open: boolean
  onOpenChange: (open: boolean) => void
  onSuccess?: () => void
}

export function DeactivateTenantDialog({
  tenant,
  open,
  onOpenChange,
  onSuccess,
}: DeactivateTenantDialogProps) {
  const deactivateTenant = useDeactivateTenant()

  const handleDeactivate = async () => {
    await deactivateTenant.mutateAsync(tenant.id, {
      onSuccess: () => {
        onOpenChange(false)
        onSuccess?.()
      },
    })
  }

  const serverError = deactivateTenant.error as
    | { response?: { data?: { message?: string } } }
    | null

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-sm">
        <DialogHeader>
          <div className="mb-2 flex h-10 w-10 items-center justify-center rounded-full bg-destructive/10">
            <AlertTriangle className="size-5 text-destructive" />
          </div>
          <DialogTitle>Deactivate Tenant</DialogTitle>
          <DialogDescription>
            Are you sure you want to permanently deactivate{" "}
            <strong className="text-foreground">{tenant.name}</strong>{" "}
            (<span className="font-mono text-xs">{tenant.tenant_code}</span>)?
            This action cannot be reversed. All tenant data will be preserved
            but the tenant will no longer be able to access the platform.
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
              deactivateTenant.reset()
              onOpenChange(false)
            }}
            disabled={deactivateTenant.isPending}
          >
            Cancel
          </Button>
          <Button
            variant="destructive"
            onClick={handleDeactivate}
            disabled={deactivateTenant.isPending}
          >
            {deactivateTenant.isPending ? "Deactivating…" : "Deactivate Tenant"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
