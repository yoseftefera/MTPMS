"use client"

/**
 * Deactivate User Confirmation Dialog.
 *
 * Shows a confirmation prompt before deactivating a user account.
 * Handles the API error case where the user has active PRs/POs.
 *
 * Validates: Requirements 4.1, 4.9, 22.6
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
import { useDeactivateUser } from "@/hooks/useUsers"
import type { User } from "@/types/models.types"

interface DeactivateUserDialogProps {
  user: User
  open: boolean
  onOpenChange: (open: boolean) => void
  onSuccess?: () => void
}

export function DeactivateUserDialog({
  user,
  open,
  onOpenChange,
  onSuccess,
}: DeactivateUserDialogProps) {
  const deactivateUser = useDeactivateUser()

  const handleDeactivate = async () => {
    await deactivateUser.mutateAsync(user.id, {
      onSuccess: () => {
        onOpenChange(false)
        onSuccess?.()
      },
    })
  }

  // Capture API error details (e.g. "user has 3 active PRs")
  const serverError = deactivateUser.error as
    | { response?: { data?: { message?: string } } }
    | null

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-sm">
        <DialogHeader>
          <div className="mb-2 flex h-10 w-10 items-center justify-center rounded-full bg-destructive/10">
            <AlertTriangle className="size-5 text-destructive" />
          </div>
          <DialogTitle>Deactivate User</DialogTitle>
          <DialogDescription>
            Are you sure you want to deactivate{" "}
            <strong className="text-foreground">{user.name}</strong>? They will
            lose access to the platform immediately. This action can be reversed
            by reactivating the account.
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
              deactivateUser.reset()
              onOpenChange(false)
            }}
            disabled={deactivateUser.isPending}
          >
            Cancel
          </Button>
          <Button
            variant="destructive"
            onClick={handleDeactivate}
            disabled={deactivateUser.isPending}
          >
            {deactivateUser.isPending ? "Deactivating…" : "Deactivate User"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
