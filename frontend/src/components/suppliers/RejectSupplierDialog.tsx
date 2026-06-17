"use client"

/**
 * Dialog to reject a pending_verification supplier registration.
 * Validates: Requirements 7.2, 7.3
 */

import { useForm } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"
import { Button } from "@/components/ui/button"
import { Textarea } from "@/components/ui/textarea"
import { Label } from "@/components/ui/label"
import { Alert, AlertDescription } from "@/components/ui/alert"
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from "@/components/ui/dialog"
import { rejectSupplierSchema, type RejectSupplierFormData } from "@/lib/validations/suppliers"
import { useRejectSupplier } from "@/hooks/useSuppliers"
import type { Supplier } from "@/types/models.types"

interface RejectSupplierDialogProps {
  supplier: Supplier
  open: boolean
  onOpenChange: (open: boolean) => void
  onSuccess?: () => void
}

export function RejectSupplierDialog({
  supplier,
  open,
  onOpenChange,
  onSuccess,
}: RejectSupplierDialogProps) {
  const reject = useRejectSupplier()

  const {
    register,
    handleSubmit,
    reset,
    formState: { errors },
  } = useForm<RejectSupplierFormData>({
    resolver: zodResolver(rejectSupplierSchema),
  })

  async function onSubmit(data: RejectSupplierFormData) {
    try {
      await reject.mutateAsync({ id: supplier.id, reason: data.reason })
      reset()
      onOpenChange(false)
      onSuccess?.()
    } catch {
      // error displayed via mutation state
    }
  }

  function handleClose() {
    reset()
    onOpenChange(false)
  }

  return (
    <Dialog open={open} onOpenChange={handleClose}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Reject Supplier Registration</DialogTitle>
          <DialogDescription>
            Rejecting the registration of <strong>{supplier.organization_name}</strong>.
            Optionally provide a reason that will be communicated to the supplier.
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={handleSubmit(onSubmit)} id="reject-supplier-form" noValidate>
          <div className="space-y-2 py-2">
            <Label htmlFor="reject-reason">Reason (optional)</Label>
            <Textarea
              id="reject-reason"
              placeholder="Provide a reason for rejection…"
              rows={3}
              aria-describedby={errors.reason ? "reject-reason-error" : undefined}
              {...register("reason")}
            />
            {errors.reason && (
              <p id="reject-reason-error" className="text-sm text-destructive" role="alert">
                {errors.reason.message}
              </p>
            )}
          </div>

          {reject.isError && (
            <Alert variant="destructive" role="alert" className="mt-2">
              <AlertDescription>
                Failed to reject supplier. Please try again.
              </AlertDescription>
            </Alert>
          )}
        </form>

        <DialogFooter className="gap-2">
          <Button
            type="button"
            variant="outline"
            onClick={handleClose}
            disabled={reject.isPending}
          >
            Cancel
          </Button>
          <Button
            type="submit"
            form="reject-supplier-form"
            variant="destructive"
            disabled={reject.isPending}
          >
            {reject.isPending ? "Rejecting…" : "Reject Registration"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
