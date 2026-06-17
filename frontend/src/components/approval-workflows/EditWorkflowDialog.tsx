"use client"

/**
 * Edit Workflow Dialog.
 *
 * Opens a modal for editing an existing approval workflow.
 * Pre-populates workflow metadata. Levels can be individually added or removed.
 *
 * Validates: Requirements 6.8, 22.7
 */

import { useEffect } from "react"
import { useForm } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"
import { Plus, Trash2 } from "lucide-react"
import { Button } from "@/components/ui/button"
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from "@/components/ui/dialog"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { Badge } from "@/components/ui/badge"
import { Alert, AlertDescription } from "@/components/ui/alert"
import {
  DOCUMENT_TYPES,
  DOCUMENT_TYPE_LABELS,
  UpdateWorkflowSchema,
  type UpdateWorkflowFormData,
} from "@/lib/validations/approvalWorkflows"
import {
  useUpdateApprovalWorkflow,
  useAddWorkflowLevel,
  useRemoveWorkflowLevel,
} from "@/hooks/useApprovalWorkflows"
import type { ApprovalWorkflow } from "@/types/models.types"

interface EditWorkflowDialogProps {
  workflow: ApprovalWorkflow
  open: boolean
  onOpenChange: (open: boolean) => void
  onSuccess?: () => void
}

export function EditWorkflowDialog({
  workflow,
  open,
  onOpenChange,
  onSuccess,
}: EditWorkflowDialogProps) {
  const updateWorkflow = useUpdateApprovalWorkflow(workflow.id)
  const addLevel = useAddWorkflowLevel(workflow.id)
  const removeLevel = useRemoveWorkflowLevel(workflow.id)

  const form = useForm<UpdateWorkflowFormData>({
    resolver: zodResolver(UpdateWorkflowSchema),
    defaultValues: {
      name: workflow.name,
      document_type: workflow.document_type,
      department_id: workflow.department_id,
    },
  })

  // Sync form when workflow prop changes
  useEffect(() => {
    form.reset({
      name: workflow.name,
      document_type: workflow.document_type,
      department_id: workflow.department_id,
    })
  }, [workflow, form])

  const handleSubmit = form.handleSubmit(async (data) => {
    await updateWorkflow.mutateAsync(
      {
        name: data.name,
        document_type: data.document_type,
        department_id: data.department_id ?? null,
      },
      {
        onSuccess: () => {
          onOpenChange(false)
          onSuccess?.()
        },
      },
    )
  })

  const handleAddLevel = () => {
    const nextOrder = (workflow.levels?.length ?? 0) + 1
    addLevel.mutate({
      level_order: nextOrder,
      approver_type: "role",
      approver_role: null,
      approver_user_id: null,
      is_parallel: false,
      escalation_hours: 48,
    })
  }

  const handleRemoveLevel = (levelId: string) => {
    removeLevel.mutate(levelId)
  }

  const serverError = updateWorkflow.error as
    | { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }
    | null

  const levels = workflow.levels ?? []

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>Edit Workflow</DialogTitle>
          <DialogDescription>
            Update the workflow details. Add or remove individual levels below.
          </DialogDescription>
        </DialogHeader>

        {serverError?.response?.data?.message && (
          <Alert variant="destructive">
            <AlertDescription>
              {serverError.response.data.message}
            </AlertDescription>
          </Alert>
        )}

        <form id="edit-workflow-form" onSubmit={handleSubmit} noValidate>
          <div className="space-y-4">
            {/* Workflow Name */}
            <div className="space-y-1">
              <Label htmlFor="edit-workflow-name">Workflow Name *</Label>
              <Input
                id="edit-workflow-name"
                {...form.register("name")}
                aria-describedby={
                  form.formState.errors.name ? "edit-name-error" : undefined
                }
              />
              {form.formState.errors.name && (
                <p id="edit-name-error" className="text-xs text-destructive">
                  {form.formState.errors.name.message}
                </p>
              )}
            </div>

            {/* Document Type */}
            <div className="space-y-1">
              <Label htmlFor="edit-document-type">Document Type *</Label>
              <Select
                value={form.watch("document_type") ?? ""}
                onValueChange={(val) =>
                  form.setValue(
                    "document_type",
                    val as UpdateWorkflowFormData["document_type"],
                    { shouldValidate: true },
                  )
                }
              >
                <SelectTrigger id="edit-document-type" aria-label="Select document type">
                  <SelectValue placeholder="Select document type" />
                </SelectTrigger>
                <SelectContent>
                  {DOCUMENT_TYPES.map((dt) => (
                    <SelectItem key={dt} value={dt}>
                      {DOCUMENT_TYPE_LABELS[dt]}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              {form.formState.errors.document_type && (
                <p className="text-xs text-destructive">
                  {form.formState.errors.document_type.message}
                </p>
              )}
            </div>

            {/* Department */}
            <div className="space-y-1">
              <Label htmlFor="edit-department-id">
                Department <span className="text-muted-foreground">(optional)</span>
              </Label>
              <Input
                id="edit-department-id"
                placeholder="Department UUID"
                {...form.register("department_id")}
                aria-label="Department ID (optional)"
              />
            </div>

            {/* Levels section */}
            <div className="space-y-2">
              <div className="flex items-center justify-between">
                <Label className="text-sm font-medium">
                  Approval Levels
                  <Badge variant="secondary" className="ml-2">
                    {levels.length}
                  </Badge>
                </Label>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  onClick={handleAddLevel}
                  disabled={addLevel.isPending || levels.length >= 10}
                  aria-label="Add approval level"
                >
                  <Plus className="size-3.5" />
                  {addLevel.isPending ? "Adding…" : "Add Level"}
                </Button>
              </div>

              {levels.length === 0 && (
                <p className="rounded-lg border border-dashed border-border py-6 text-center text-sm text-muted-foreground">
                  No levels yet. Add at least one approval level.
                </p>
              )}

              <div className="space-y-2">
                {levels
                  .slice()
                  .sort((a, b) => a.level_order - b.level_order)
                  .map((level) => (
                    <div
                      key={level.id}
                      className="flex items-center justify-between rounded-lg border border-border bg-muted/30 px-4 py-3"
                    >
                      <div className="flex items-center gap-3">
                        <Badge variant="outline" className="font-mono text-xs">
                          L{level.level_order}
                        </Badge>
                        <div className="text-sm">
                          <span className="font-medium">
                            {level.approver_type === "role"
                              ? (level.approver_role ?? "—")
                              : `User: ${level.approver_user_id ?? "—"}`}
                          </span>
                          <span className="ml-2 text-xs text-muted-foreground">
                            {level.is_parallel ? "Parallel" : "Sequential"} ·{" "}
                            {level.escalation_hours}h escalation
                          </span>
                        </div>
                      </div>
                      <Button
                        type="button"
                        variant="ghost"
                        size="icon-sm"
                        onClick={() => handleRemoveLevel(level.id)}
                        disabled={removeLevel.isPending}
                        aria-label={`Remove level ${level.level_order}`}
                        className="text-destructive hover:bg-destructive/10 hover:text-destructive"
                      >
                        <Trash2 className="size-4" />
                      </Button>
                    </div>
                  ))}
              </div>
            </div>
          </div>
        </form>

        <DialogFooter>
          <Button
            variant="outline"
            onClick={() => onOpenChange(false)}
            disabled={updateWorkflow.isPending}
          >
            Cancel
          </Button>
          <Button
            type="submit"
            form="edit-workflow-form"
            disabled={updateWorkflow.isPending}
          >
            {updateWorkflow.isPending ? "Saving…" : "Save Changes"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
