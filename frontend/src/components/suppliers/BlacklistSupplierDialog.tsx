"use client"

/**
 * Dialog to blacklist an active supplier with a documented reason.
 * Validates: Requirements 7.4, 7.5
 */

import { useForm } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"
import { AlertTriangle } from "lucide-react"
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
import { blacklistSupplierSchema, type BlacklistSupplierFormData } from "@/lib/validations/suppliers"
import { useBlacklistSupplier } from "@/hooks/useSuppliers"
import type { Supplier } from "@/types/models.types"

interface BlacklistSupplierDialogProps {
  supplier: Supplier
  open: boolean
  onOpenChange: (open: boolean) => void
  onSuccess?: () => void
}

export function BlacklistSupplierDialog({
  supplier,
  open,
  onOpenChange,
  onSuccess,
}: BlacklistSupplierDialogProps) {
  const blacklist = useBlacklistSupplier()

  const {
    register,
    handleSubmit,
    reset,
    formState: { errors },
  } = useForm<BlacklistSupplierFormData>({
    resolver: zodResolver(blacklistSupplierSchema),
  })

  async function onSubmit(data: BlacklistSupplierFormData) {
    try {
      await blacklist.mutateAsync({ id: supplier.id, payload: { reason: data.reason } })
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
          <DialogTitle className="flex items-center gap-2 text-destructive">
            <AlertTriangle className="size-5" aria-hidden="true" />
            Blacklist Supplier
          </DialogTitle>
          <DialogDescription>
            Blacklisting <strong>{supplier.organization_name}</strong> will exclude them from
            all future tender invitations and prevent them from submitting bids or receiving
            purchase orders.
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={handleSubmit(onSubmit)} id="blacklist-form" noValidate>
          <div className="space-y-2 py-2">
            <Label htmlFor="blacklist-reason">
              Reason <span aria-hidden="true" className="text-destructive">*</span>
            </Label>
            <Textarea
              id="blacklist-reason"
              placeholder="Provide a detailed reason for blacklisting this supplier…"
              rows={4}
              aria-required="true"
              aria-describedby={errors.reason ? "blacklist-reason-error" : undefined}
              {...register("reason")}
            />
            {errors.reason && (
              <p id="blacklist-reason-error" className="text-sm text-destructive" role="alert">
                {errors.reason.message}
              </p>
            )}
          </div>

          {blacklist.isError && (
            <Alert variant="destructive" role="alert" className="mt-2">
              <AlertDescription>
                Failed to blacklist supplier. Please try again.
              </AlertDescription>
            </Alert>
          )}
        </form>

        <DialogFooter className="gap-2">
          <Button
            type="button"
            variant="outline"
            onClick={handleClose}
            disabled={blacklist.isPending}
          >
            Cancel
          </Button>
          <Button
            type="submit"
            form="blacklist-form"
            variant="destructive"
            disabled={blacklist.isPending}
          >
            {blacklist.isPending ? "Blacklisting…" : "Blacklist Supplier"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
