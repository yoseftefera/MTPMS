"use client"

/**
 * ReturnForRevisionDialog — dialog for returning a document for revision.
 *
 * Requires comments with a minimum of 10 characters.
 * Uses React Hook Form + Zod validation.
 *
 * Validates: Requirements 22.5
 */

import { useForm } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"
import { RotateCcw } from "lucide-react"
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
import { ReturnSchema, type ReturnFormData } from "@/lib/validations/approvals"
import { useReturnForRevision } from "@/hooks/useApprovals"
import type { Approval } from "@/types/models.types"

interface ReturnForRevisionDialogProps {
  approval: Approval
  open: boolean
  onOpenChange: (open: boolean) => void
  onSuccess?: () => void
}

export function ReturnForRevisionDialog({
  approval,
  open,
  onOpenChange,
  onSuccess,
}: ReturnForRevisionDialogProps) {
  const returnForRevision = useReturnForRevision()

  const form = useForm<ReturnFormData>({
    resolver: zodResolver(ReturnSchema),
    defaultValues: {
      comments: "",
    },
  })

  const handleSubmit = form.handleSubmit(async (data) => {
    await returnForRevision.mutateAsync(
      {
        approvalId: approval.id,
        payload: { comments: data.comments },
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

  const serverError = returnForRevision.error as
    | { response?: { data?: { message?: string } } }
    | null

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <RotateCcw className="size-5 text-amber-600" aria-hidden="true" />
            Return for Revision
          </DialogTitle>
          <DialogDescription>
            Describe what changes are required. The document owner will be
            notified and can resubmit after making the requested changes.
          </DialogDescription>
        </DialogHeader>

        <DocumentPreview approval={approval} />

        {serverError?.response?.data?.message && (
          <Alert variant="destructive">
            <AlertDescription>{serverError.response.data.message}</AlertDescription>
          </Alert>
        )}

        <form id="return-form" onSubmit={handleSubmit} noValidate>
          <div className="space-y-1">
            <Label htmlFor="return-comments">
              Revision Comments <span className="text-destructive">*</span>
            </Label>
            <Textarea
              id="return-comments"
              placeholder="Describe the changes needed (minimum 10 characters)…"
              rows={4}
              {...form.register("comments")}
              aria-describedby={
                form.formState.errors.comments ? "return-comments-error" : undefined
              }
            />
            {form.formState.errors.comments && (
              <p id="return-comments-error" className="text-xs text-destructive">
                {form.formState.errors.comments.message}
              </p>
            )}
          </div>
        </form>

        <DialogFooter>
          <Button
            variant="outline"
            onClick={handleClose}
            disabled={returnForRevision.isPending}
          >
            Cancel
          </Button>
          <Button
            type="submit"
            form="return-form"
            disabled={returnForRevision.isPending}
            className="bg-amber-600 hover:bg-amber-700 text-white"
          >
            {returnForRevision.isPending ? "Returning…" : "Return for Revision"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
