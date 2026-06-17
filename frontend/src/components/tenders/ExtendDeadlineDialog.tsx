"use client"

/**
 * ExtendDeadlineDialog.
 *
 * Allows a Procurement_Officer to extend the submission deadline of a
 * published tender before the original deadline passes.
 *
 * Validates: Requirements 8.8, 22.7
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
import { Input } from "@/components/ui/input"
import { Alert, AlertDescription } from "@/components/ui/alert"
import { extendDeadlineSchema, type ExtendDeadlineFormData } from "@/lib/validations/tenders"
import { useExtendDeadline } from "@/hooks/useTenders"
import type { TenderDetail } from "@/types/tender"

interface ExtendDeadlineDialogProps {
  tender: TenderDetail
  open: boolean
  onOpenChange: (open: boolean) => void
  onSuccess?: () => void
}

function toDatetimeLocal(iso: string): string {
  if (!iso) return ""
  return iso.slice(0, 16)
}

export function ExtendDeadlineDialog({
  tender,
  open,
  onOpenChange,
  onSuccess,
}: ExtendDeadlineDialogProps) {
  const extendDeadline = useExtendDeadline()

  const {
    register,
    handleSubmit,
    reset,
    formState: { errors },
  } = useForm<ExtendDeadlineFormData>({
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    resolver: zodResolver(extendDeadlineSchema) as any,
    defaultValues: {
      submission_deadline: toDatetimeLocal(tender.submission_deadline),
    },
  })

  const onSubmit = handleSubmit(async ({ submission_deadline }) => {
    try {
      await extendDeadline.mutateAsync({
        id: tender.id,
        newDeadline: submission_deadline,
      })
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

  const errorMessage = (
    extendDeadline.error as { response?: { data?: { message?: string } } }
  )?.response?.data?.message

  return (
    <Dialog open={open} onOpenChange={handleClose}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Extend Deadline</DialogTitle>
          <DialogDescription>
            Set a new submission deadline for{" "}
            <strong>{tender.reference_number}</strong>. The new deadline must be
            after the current deadline.
          </DialogDescription>
        </DialogHeader>

        {extendDeadline.isError && (
          <Alert variant="destructive" role="alert">
            <AlertDescription>
              {errorMessage ?? "Failed to extend deadline. Please try again."}
            </AlertDescription>
          </Alert>
        )}

        <form
          id="extend-deadline-form"
          onSubmit={onSubmit}
          noValidate
          className="space-y-4"
        >
          {/* Current deadline for reference */}
          <div className="rounded-md bg-muted/40 px-4 py-3 text-sm text-muted-foreground">
            Current deadline:{" "}
            <span className="font-medium text-foreground">
              {new Intl.DateTimeFormat("en-US", {
                dateStyle: "medium",
                timeStyle: "short",
              }).format(new Date(tender.submission_deadline))}
            </span>
          </div>

          <div className="space-y-1.5">
            <Label htmlFor="new-deadline">
              New Deadline{" "}
              <span aria-hidden="true" className="text-destructive">
                *
              </span>
            </Label>
            <Input
              id="new-deadline"
              type="datetime-local"
              aria-invalid={!!errors.submission_deadline}
              aria-describedby={
                errors.submission_deadline ? "new-deadline-error" : undefined
              }
              {...register("submission_deadline")}
            />
            {errors.submission_deadline && (
              <p
                id="new-deadline-error"
                role="alert"
                className="text-xs text-destructive"
              >
                {errors.submission_deadline.message}
              </p>
            )}
          </div>
        </form>

        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            onClick={handleClose}
            disabled={extendDeadline.isPending}
          >
            Cancel
          </Button>
          <Button
            type="submit"
            form="extend-deadline-form"
            disabled={extendDeadline.isPending}
          >
            {extendDeadline.isPending ? "Saving…" : "Extend Deadline"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
