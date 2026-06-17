"use client"

/**
 * ApproveDialog — confirm dialog for approving a pending document.
 *
 * Includes an optional comment textarea.
 * Uses React Hook Form + Zod validation.
 *
 * Validates: Requirements 22.5
 */

import { useForm } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"
import { CheckCircle2 } from "lucide-react"
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
import { ApproveSchema, type ApproveFormData } from "@/lib/validations/approvals"
import { useApproveDocument } from "@/hooks/useApprovals"
import type { Approval } from "@/types/models.types"

interface ApproveDialogProps {
  approval: Approval
  open: boolean
  onOpenChange: (open: boolean) => void
  onSuccess?: () => void
}

export function ApproveDialog({
  approval,
  open,
  onOpenChange,
  onSuccess,
}: ApproveDialogProps) {
  const approveDocument = useApproveDocument()

  const form = useForm<ApproveFormData>({
    resolver: zodResolver(ApproveSchema),
    defaultValues: {
      comment: "",
    },
  })

  const handleSubmit = form.handleSubmit(async (data) => {
    await approveDocument.mutateAsync(
      {
        approvalId: approval.id,
        payload: {
          comment: data.comment || undefined,
        },
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

  const serverError = approveDocument.error as
    | { response?: { data?: { message?: string } } }
    | null

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <CheckCircle2 className="size-5 text-green-600" aria-hidden="true" />
            Approve Document
          </DialogTitle>
          <DialogDescription>
            Review the document below and confirm your approval.
          </DialogDescription>
        </DialogHeader>

        <DocumentPreview approval={approval} />

        {serverError?.response?.data?.message && (
          <Alert variant="destructive">
            <AlertDescription>{serverError.response.data.message}</AlertDescription>
          </Alert>
        )}

        <form id="approve-form" onSubmit={handleSubmit} noValidate>
          <div className="space-y-1">
            <Label htmlFor="approve-comment">
              Comment{" "}
              <span className="text-muted-foreground text-xs">(optional)</span>
            </Label>
            <Textarea
              id="approve-comment"
              placeholder="Add an optional comment…"
              rows={3}
              {...form.register("comment")}
              aria-describedby={
                form.formState.errors.comment ? "approve-comment-error" : undefined
              }
            />
            {form.formState.errors.comment && (
              <p id="approve-comment-error" className="text-xs text-destructive">
                {form.formState.errors.comment.message}
              </p>
            )}
          </div>
        </form>

        <DialogFooter>
          <Button
            variant="outline"
            onClick={handleClose}
            disabled={approveDocument.isPending}
          >
            Cancel
          </Button>
          <Button
            type="submit"
            form="approve-form"
            disabled={approveDocument.isPending}
            className="bg-green-600 hover:bg-green-700 text-white"
          >
            {approveDocument.isPending ? "Approving…" : "Approve"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
