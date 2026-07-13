"use client"

/**
 * Reactivate Tenant Confirmation Dialog.
 *
 * Confirms before reactivating a suspended tenant, restoring access for all
 * tenant users.
 *
 * Validates: Requirements 1.5, 1.6, 1.10
 */

import { PlayCircle } from "lucide-react"
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
import { useReactivateTenant } from "@/hooks/useTenants"
import type { Tenant } from "@/types/models.types"

interface ReactivateTenantDialogProps {
  tenant: Tenant
  open: boolean
  onOpenChange: (open: boolean) => void
  onSuccess?: () => void
}

export function ReactivateTenantDialog({
  tenant,
  open,
  onOpenChange,
  onSuccess,
}: ReactivateTenantDialogProps) {
  const reactivateTenant = useReactivateTenant()

  const handleReactivate = async () => {
    await reactivateTenant.mutateAsync(tenant.id, {
      onSuccess: () => {
        onOpenChange(false)
        onSuccess?.()
      },
    })
  }

  const serverError = reactivateTenant.error as
    | { response?: { data?: { message?: string } } }
    | null

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-sm">
        <DialogHeader>
          <div className="mb-2 flex h-10 w-10 items-center justify-center rounded-full bg-green-100 dark:bg-green-900/30">
            <PlayCircle className="size-5 text-green-700 dark:text-green-400" />
          </div>
          <DialogTitle>Reactivate Tenant</DialogTitle>
          <DialogDescription>
            Reactivate{" "}
            <strong className="text-foreground">{tenant.name}</strong>{" "}
            (<span className="font-mono text-xs">{tenant.tenant_code}</span>)?
            All tenant users will regain access to the platform immediately.
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
              reactivateTenant.reset()
              onOpenChange(false)
            }}
            disabled={reactivateTenant.isPending}
          >
            Cancel
          </Button>
          <Button
            onClick={handleReactivate}
            disabled={reactivateTenant.isPending}
            className="bg-green-600 text-white hover:bg-green-700 dark:bg-green-700 dark:hover:bg-green-600"
          >
            {reactivateTenant.isPending ? "Reactivating…" : "Reactivate Tenant"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
