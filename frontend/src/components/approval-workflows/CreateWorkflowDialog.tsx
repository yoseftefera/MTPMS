"use client"

/**
 * Create Workflow Dialog.
 *
 * Opens a modal form for creating a new approval workflow with levels.
 * Uses React Hook Form + Zod validation.
 *
 * Validates: Requirements 6.8, 22.7
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
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { Alert, AlertDescription } from "@/components/ui/alert"
import { WorkflowLevelBuilder } from "./WorkflowLevelBuilder"
import {
  CreateWorkflowSchema,
  DOCUMENT_TYPES,
  DOCUMENT_TYPE_LABELS,
  type CreateWorkflowFormData,
} from "@/lib/validations/approvalWorkflows"
import { useCreateApprovalWorkflow } from "@/hooks/useApprovalWorkflows"

interface CreateWorkflowDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  onSuccess?: () => void
}

export function CreateWorkflowDialog({
  open,
  onOpenChange,
  onSuccess,
}: CreateWorkflowDialogProps) {
  const createWorkflow = useCreateApprovalWorkflow()

  const form = useForm<CreateWorkflowFormData>({
    resolver: zodResolver(CreateWorkflowSchema),
    defaultValues: {
      name: "",
      document_type: undefined,
      department_id: null,
      levels: [],
    },
  })

  const handleSubmit = form.handleSubmit(async (data) => {
    await createWorkflow.mutateAsync(
      {
        name: data.name,
        document_type: data.document_type,
        department_id: data.department_id ?? null,
        levels: data.levels.map((l, i) => ({
          level_order: i + 1,
          approver_type: l.approver_type,
          approver_role: l.approver_role ?? null,
          approver_user_id: l.approver_user_id ?? null,
          is_parallel: l.is_parallel,
          escalation_hours: l.escalation_hours,
        })),
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

  const serverError = createWorkflow.error as
    | { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }
    | null

  const handleClose = () => {
    form.reset()
    onOpenChange(false)
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>Create Approval Workflow</DialogTitle>
          <DialogDescription>
            Define the workflow name, document type, and approval levels.
          </DialogDescription>
        </DialogHeader>

        {serverError?.response?.data?.message && (
          <Alert variant="destructive">
            <AlertDescription>
              {serverError.response.data.message}
              {serverError.response.data.errors && (
                <ul className="mt-1 list-disc pl-4 text-xs">
                  {Object.entries(serverError.response.data.errors).map(([field, msgs]) =>
                    msgs.map((msg, i) => (
                      <li key={`${field}-${i}`}>{msg}</li>
                    )),
                  )}
                </ul>
              )}
            </AlertDescription>
          </Alert>
        )}

        <form id="create-workflow-form" onSubmit={handleSubmit} noValidate>
          <div className="space-y-4">
            {/* Workflow Name */}
            <div className="space-y-1">
              <Label htmlFor="workflow-name">Workflow Name *</Label>
              <Input
                id="workflow-name"
                placeholder="e.g. Purchase Request Approval"
                {...form.register("name")}
                aria-describedby={
                  form.formState.errors.name ? "workflow-name-error" : undefined
                }
              />
              {form.formState.errors.name && (
                <p id="workflow-name-error" className="text-xs text-destructive">
                  {form.formState.errors.name.message}
                </p>
              )}
            </div>

            {/* Document Type */}
            <div className="space-y-1">
              <Label htmlFor="document-type">Document Type *</Label>
              <Select
                value={form.watch("document_type") ?? ""}
                onValueChange={(val) =>
                  form.setValue(
                    "document_type",
                    val as CreateWorkflowFormData["document_type"],
                    { shouldValidate: true },
                  )
                }
              >
                <SelectTrigger id="document-type" aria-label="Select document type">
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

            {/* Department (optional) */}
            <div className="space-y-1">
              <Label htmlFor="department-id">
                Department <span className="text-muted-foreground">(optional)</span>
              </Label>
              <Input
                id="department-id"
                placeholder="Department UUID (leave blank for all departments)"
                {...form.register("department_id")}
                aria-label="Department ID (optional)"
              />
              {form.formState.errors.department_id && (
                <p className="text-xs text-destructive">
                  {form.formState.errors.department_id.message}
                </p>
              )}
            </div>

            {/* Level builder */}
            <WorkflowLevelBuilder form={form} />
          </div>
        </form>

        <DialogFooter>
          <Button
            variant="outline"
            onClick={handleClose}
            disabled={createWorkflow.isPending}
          >
            Cancel
          </Button>
          <Button
            type="submit"
            form="create-workflow-form"
            disabled={createWorkflow.isPending}
          >
            {createWorkflow.isPending ? "Creating…" : "Create Workflow"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
