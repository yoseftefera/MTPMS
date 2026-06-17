"use client"

/**
 * RejectDialog — dialog for rejecting a pending document.
 *
 * Requires a reason with a minimum of 10 characters.
 * Uses React Hook Form + Zod validation.
 *
 * Validates: Requirements 22.5
 */

import { useForm } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"
import { XCircle } from "lucide-react"
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
import { DocumentPreview } from "./DocumentPreview"
import { RejectSchema, type RejectFormData } from "@/lib/validations/approvals"
import { useRejectDocument } from "@/hooks/useApprovals"
import type { Approval } from "@/types/models.types"

interface RejectDialogProps {
  approval: Approval
  open: boolean
  onOpenChange: (open: boolean) => void
  onSuccess?: () => void
}

export function RejectDialog({
  approval,
  open,
  onOpenChange,
  onSuccess,
}: RejectDialogProps) {
  const rejectDocument = useRejectDocument()

  const form = useForm<RejectFormData>({
    resolver: zodResolver(RejectSchema),
    defaultValues: {
      reason: "",
    },
  })

  const handleSubmit = form.handleSubmit(async (data) => {
    await rejectDocument.mutateAsync(
      {
        approvalId: approval.id,
        payload: { reason: data.reason },
      },
      {
        onSuccess: () => {
          form.reset()
          onOpenChange(false)
          onSuccess?.()
        },
      },
    )
  })

  const handleClose = () => {
    form.reset()
    onOpenChange(false)
  }

  const serverError = rejectDocument.error as
    | { response?: { data?: { message?: string } } }
    | null

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <XCircle className="size-5 text-destructive" aria-hidden="true" />
            Reject Document
          </DialogTitle>
          <DialogDescription>
            Provide a reason for the rejection. This will be recorded and
            visible to the document owner.
          </DialogDescription>
        </DialogHeader>

        <DocumentPreview approval={approval} />

        {serverError?.response?.data?.message && (
          <Alert variant="destructive">
            <AlertDescription>{serverError.response.data.message}</AlertDescription>
          </Alert>
        )}

        <form id="reject-form" onSubmit={handleSubmit} noValidate>
          <div className="space-y-1">
            <Label htmlFor="reject-reason">
              Reason <span className="text-destructive">*</span>
            </Label>
            <Textarea
              id="reject-reason"
              placeholder="Enter the rejection reason (minimum 10 characters)…"
              rows={4}
              {...form.register("reason")}
              aria-describedby={
                form.formState.errors.reason ? "reject-reason-error" : undefined
              }
            />
            {form.formState.errors.reason && (
              <p id="reject-reason-error" className="text-xs text-destructive">
                {form.formState.errors.reason.message}
              </p>
            )}
          </div>
        </form>

        <DialogFooter>
          <Button
            variant="outline"
            onClick={handleClose}
            disabled={rejectDocument.isPending}
          >
            Cancel
          </Button>
          <Button
            type="submit"
            form="reject-form"
            variant="destructive"
            disabled={rejectDocument.isPending}
          >
            {rejectDocument.isPending ? "Rejecting…" : "Reject"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
