"use client"

/**
 * CancelTenderDialog.
 *
 * Confirms tender cancellation and requires a documented reason.
 *
 * Validates: Requirements 8.9, 22.7
 */

import { useForm } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"
import { Button } from "@/components/ui/button"
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from "@/components/ui/dialog"
import { Label } from "@/components/ui/label"
import { Textarea } from "@/components/ui/textarea"
import { Alert, AlertDescription } from "@/components/ui/alert"
import { cancelTenderSchema, type CancelTenderFormData } from "@/lib/validations/tenders"
import { useCancelTender } from "@/hooks/useTenders"
import type { TenderDetail } from "@/types/tender"

interface CancelTenderDialogProps {
  tender: TenderDetail
  open: boolean
  onOpenChange: (open: boolean) => void
  onSuccess?: () => void
}

export function CancelTenderDialog({
  tender,
  open,
  onOpenChange,
  onSuccess,
}: CancelTenderDialogProps) {
  const cancelTender = useCancelTender()

  const {
    register,
    handleSubmit,
    reset,
    formState: { errors },
  } = useForm<CancelTenderFormData>({
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    resolver: zodResolver(cancelTenderSchema) as any,
    defaultValues: { reason: "" },
  })

  const onSubmit = handleSubmit(async ({ reason }) => {
    try {
      await cancelTender.mutateAsync({ id: tender.id, reason })
      reset()
      onOpenChange(false)
      onSuccess?.()
    } catch {
      // error surfaced via mutation state
    }
  })

  function handleClose() {
    reset()
    onOpenChange(false)
  }

  return (
    <Dialog open={open} onOpenChange={handleClose}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Cancel Tender</DialogTitle>
          <DialogDescription>
            Cancelling <strong>{tender.reference_number}</strong> will notify all
            suppliers who submitted bids. This action cannot be undone.
          </DialogDescription>
        </DialogHeader>

        {cancelTender.isError && (
          <Alert variant="destructive" role="alert">
            <AlertDescription>
              {(
                cancelTender.error as {
                  response?: { data?: { message?: string } }
                }
              )?.response?.data?.message ?? "Failed to cancel tender."}
            </AlertDescription>
          </Alert>
        )}

        <form id="cancel-tender-form" onSubmit={onSubmit} noValidate className="space-y-4">
          <div className="space-y-1.5">
            <Label htmlFor="cancel-reason">
              Cancellation Reason{" "}
              <span aria-hidden="true" className="text-destructive">*</span>
            </Label>
            <Textarea
              id="cancel-reason"
              placeholder="Explain why this tender is being cancelled…"
              rows={4}
              aria-invalid={!!errors.reason}
              aria-describedby={errors.reason ? "cancel-reason-error" : undefined}
              {...register("reason")}
            />
            {errors.reason && (
              <p id="cancel-reason-error" role="alert" className="text-xs text-destructive">
                {errors.reason.message}
              </p>
            )}
          </div>
        </form>

        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            onClick={handleClose}
            disabled={cancelTender.isPending}
          >
            Go Back
          </Button>
          <Button
            type="submit"
            form="cancel-tender-form"
            variant="destructive"
            disabled={cancelTender.isPending}
          >
            {cancelTender.isPending ? "Cancelling…" : "Cancel Tender"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
