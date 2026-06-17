"use client"

/**
 * Create Purchase Request dialog form.
 *
 * Features:
 * - React Hook Form + Zod validation
 * - Dynamic line items with useFieldArray
 * - File attachment input (PDF, DOCX, XLSX, PNG, JPG, JPEG, max 10 MB each)
 * - Running line subtotals + grand total
 * - Budget validation error feedback (available_balance + shortfall)
 * - On success: invalidates PR list, shows success message, closes dialog
 *
 * Validates: Requirements 5.2, 5.5, 22.5, 22.6, 22.7
 */

import { useState } from "react"
import { useForm, useFieldArray } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"
import { Plus, Trash2, Paperclip, X, AlertTriangle } from "lucide-react"
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
import { Textarea } from "@/components/ui/textarea"
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { Separator } from "@/components/ui/separator"
import { createPRSchema, type CreatePRFormData } from "@/lib/validations/purchaseRequests"
import { useCreatePR } from "@/hooks/usePurchaseRequest"
import { formatCurrency } from "@/lib/utils"

// ─── Constants ────────────────────────────────────────────────────────────────

const CURRENCIES = ["USD", "EUR", "GBP", "ETB", "KES", "NGN", "GHS", "ZAR"]

const ALLOWED_TYPES = [
  "application/pdf",
  "application/msword",
  "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
  "application/vnd.ms-excel",
  "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
  "image/png",
  "image/jpeg",
]
const MAX_FILE_SIZE = 10 * 1024 * 1024 // 10 MB

// ─── Types ────────────────────────────────────────────────────────────────────

interface Department {
  id: string
  name: string
}

interface BudgetError {
  available_balance: string
  shortfall: string
}

interface CreatePRFormProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  departments: Department[]
  onSuccess?: () => void
}

// ─── Helpers ──────────────────────────────────────────────────────────────────

function calcLineTotal(quantity: string, price: string): number {
  const q = parseFloat(quantity)
  const p = parseFloat(price)
  return isNaN(q) || isNaN(p) ? 0 : q * p
}

function formatFileSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

// ─── Component ────────────────────────────────────────────────────────────────

export function CreatePRForm({
  open,
  onOpenChange,
  departments,
  onSuccess,
}: CreatePRFormProps) {
  const createPR = useCreatePR()
  const [attachedFiles, setAttachedFiles] = useState<File[]>([])
  const [fileError, setFileError] = useState<string | null>(null)
  const [budgetError, setBudgetError] = useState<BudgetError | null>(null)
  const [successMessage, setSuccessMessage] = useState<string | null>(null)

  const {
    register,
    handleSubmit,
    control,
    setValue,
    watch,
    reset,
    formState: { errors },
  } = useForm<CreatePRFormData>({
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    resolver: zodResolver(createPRSchema) as any,
    defaultValues: {
      title: "",
      department_id: "",
      description: "",
      required_date: "",
      currency: "USD",
      items: [
        {
          description: "",
          quantity: "",
          unit_of_measure: "",
          estimated_unit_price: "",
          budget_code: "",
        },
      ],
    },
  })

  const { fields, append, remove } = useFieldArray({ control, name: "items" })

  const currency = watch("currency")
  const items = watch("items")

  // ── Computed totals ──────────────────────────────────────────────────────────

  const lineTotals = items.map((item) =>
    calcLineTotal(item.quantity, item.estimated_unit_price),
  )
  const grandTotal = lineTotals.reduce((sum, t) => sum + t, 0)

  // ── File attachment handling ─────────────────────────────────────────────────

  function handleFileChange(e: React.ChangeEvent<HTMLInputElement>) {
    setFileError(null)
    const files = Array.from(e.target.files ?? [])
    const validated: File[] = []

    for (const file of files) {
      if (!ALLOWED_TYPES.includes(file.type)) {
        setFileError(
          `"${file.name}" is not an allowed file type. Allowed: PDF, DOCX, XLSX, PNG, JPG.`,
        )
        continue
      }
      if (file.size > MAX_FILE_SIZE) {
        setFileError(`"${file.name}" exceeds the 10 MB size limit.`)
        continue
      }
      validated.push(file)
    }

    setAttachedFiles((prev) => [...prev, ...validated])
    // Reset input so the same file can be re-added if removed
    e.target.value = ""
  }

  function removeFile(index: number) {
    setAttachedFiles((prev) => prev.filter((_, i) => i !== index))
  }

  // ── Form submit ──────────────────────────────────────────────────────────────

  const onSubmit = handleSubmit(async (data) => {
    setBudgetError(null)
    setSuccessMessage(null)

    try {
      await createPR.mutateAsync({
        title: data.title,
        department_id: data.department_id,
        description: data.description || undefined,
        required_date: data.required_date || undefined,
        currency: data.currency,
        items: data.items.map((item: CreatePRFormData["items"][number]) => ({
          description: item.description,
          quantity: item.quantity,
          unit_of_measure: item.unit_of_measure,
          estimated_unit_price: item.estimated_unit_price,
          budget_code: item.budget_code || undefined,
        })),
      })

      setSuccessMessage("Purchase request created successfully.")
      setTimeout(() => {
        handleClose()
        onSuccess?.()
      }, 800)
    } catch (err: unknown) {
      const apiErr = err as {
        response?: {
          data?: {
            success: boolean
            data?: { available_balance?: string; shortfall?: string }
            errors?: { budget?: string[] }
          }
        }
      }
      const errData = apiErr?.response?.data
      if (errData && !errData.success && errData.data?.available_balance !== undefined) {
        setBudgetError({
          available_balance: errData.data.available_balance ?? "0.00",
          shortfall: errData.data.shortfall ?? "0.00",
        })
      }
    }
  })

  function handleClose() {
    reset()
    setAttachedFiles([])
    setFileError(null)
    setBudgetError(null)
    setSuccessMessage(null)
    onOpenChange(false)
  }

  // ─── Render ───────────────────────────────────────────────────────────────────

  return (
    <Dialog open={open} onOpenChange={handleClose}>
      <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-3xl">
        <DialogHeader>
          <DialogTitle>Create Purchase Request</DialogTitle>
          <DialogDescription>
            Fill in the details for your new purchase request. At least one line item is required.
          </DialogDescription>
        </DialogHeader>

        {/* Success message */}
        {successMessage && (
          <Alert role="status">
            <AlertDescription>{successMessage}</AlertDescription>
          </Alert>
        )}

        {/* Budget validation error */}
        {budgetError && (
          <Alert variant="destructive" role="alert" aria-live="assertive">
            <AlertTriangle className="size-4" aria-hidden="true" />
            <AlertTitle>Insufficient Budget</AlertTitle>
            <AlertDescription className="space-y-1">
              <p>
                Available balance:{" "}
                <strong>{formatCurrency(budgetError.available_balance, currency)}</strong>
              </p>
              <p>
                Shortfall:{" "}
                <strong className="text-destructive">
                  {formatCurrency(budgetError.shortfall, currency)}
                </strong>
              </p>
              <p className="text-xs mt-1">
                Please reduce your item quantities/prices or contact your Finance Officer.
              </p>
            </AlertDescription>
          </Alert>
        )}

        {/* Generic server error */}
        {createPR.isError && !budgetError && (
          <Alert variant="destructive" role="alert">
            <AlertDescription>
              {(
                createPR.error as {
                  response?: { data?: { message?: string } }
                }
              )?.response?.data?.message ?? "Failed to create purchase request. Please try again."}
            </AlertDescription>
          </Alert>
        )}

        <form id="create-pr-form" onSubmit={onSubmit} noValidate className="space-y-6">
          {/* ── Basic info ──────────────────────────────────────────────── */}
          <section aria-labelledby="pr-basic-heading">
            <h3 id="pr-basic-heading" className="sr-only">
              Basic Information
            </h3>

            <div className="space-y-4">
              {/* Title */}
              <div className="space-y-1.5">
                <Label htmlFor="pr-title">
                  Title <span aria-hidden="true" className="text-destructive">*</span>
                </Label>
                <Input
                  id="pr-title"
                  placeholder="e.g. Office Supplies Q3"
                  aria-invalid={!!errors.title}
                  aria-describedby={errors.title ? "pr-title-error" : undefined}
                  {...register("title")}
                />
                {errors.title && (
                  <p id="pr-title-error" role="alert" className="text-xs text-destructive">
                    {errors.title.message}
                  </p>
                )}
              </div>

              {/* Department + Currency (inline) */}
              <div className="grid gap-4 sm:grid-cols-2">
                <div className="space-y-1.5">
                  <Label htmlFor="pr-department">
                    Department <span aria-hidden="true" className="text-destructive">*</span>
                  </Label>
                  <Select
                    value={watch("department_id")}
                    onValueChange={(val) =>
                      setValue("department_id", val, { shouldValidate: true })
                    }
                  >
                    <SelectTrigger
                      id="pr-department"
                      aria-label="Select department"
                      aria-invalid={!!errors.department_id}
                      aria-describedby={
                        errors.department_id ? "pr-dept-error" : undefined
                      }
                    >
                      <SelectValue placeholder="Select department…" />
                    </SelectTrigger>
                    <SelectContent>
                      {departments.map((dept) => (
                        <SelectItem key={dept.id} value={dept.id}>
                          {dept.name}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                  {errors.department_id && (
                    <p id="pr-dept-error" role="alert" className="text-xs text-destructive">
                      {errors.department_id.message}
                    </p>
                  )}
                </div>

                <div className="space-y-1.5">
                  <Label htmlFor="pr-currency">Currency</Label>
                  <Select
                    value={currency}
                    onValueChange={(val) =>
                      setValue("currency", val, { shouldValidate: true })
                    }
                  >
                    <SelectTrigger id="pr-currency" aria-label="Select currency">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {CURRENCIES.map((c) => (
                        <SelectItem key={c} value={c}>
                          {c}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
              </div>

              {/* Description + Required Date (inline) */}
              <div className="grid gap-4 sm:grid-cols-2">
                <div className="space-y-1.5">
                  <Label htmlFor="pr-description">Description</Label>
                  <Textarea
                    id="pr-description"
                    placeholder="Optional additional context…"
                    rows={3}
                    aria-invalid={!!errors.description}
                    aria-describedby={errors.description ? "pr-desc-error" : undefined}
                    {...register("description")}
                  />
                  {errors.description && (
                    <p id="pr-desc-error" role="alert" className="text-xs text-destructive">
                      {errors.description.message}
                    </p>
                  )}
                </div>

                <div className="space-y-1.5">
                  <Label htmlFor="pr-required-date">Required Date</Label>
                  <Input
                    id="pr-required-date"
                    type="date"
                    aria-invalid={!!errors.required_date}
                    aria-describedby={errors.required_date ? "pr-date-error" : undefined}
                    {...register("required_date")}
                  />
                  {errors.required_date && (
                    <p id="pr-date-error" role="alert" className="text-xs text-destructive">
                      {errors.required_date.message}
                    </p>
                  )}
                </div>
              </div>
            </div>
          </section>

          <Separator />

          {/* ── Line items ──────────────────────────────────────────────── */}
          <section aria-labelledby="pr-items-heading">
            <div className="mb-3 flex items-center justify-between">
              <h3
                id="pr-items-heading"
                className="text-sm font-semibold text-foreground"
              >
                Line Items{" "}
                <span aria-hidden="true" className="text-destructive">*</span>
              </h3>
              <Button
                type="button"
                size="sm"
                variant="outline"
                onClick={() =>
                  append({
                    description: "",
                    quantity: "",
                    unit_of_measure: "",
                    estimated_unit_price: "",
                    budget_code: "",
                  })
                }
                aria-label="Add line item"
              >
                <Plus className="size-3.5" aria-hidden="true" />
                Add Item
              </Button>
            </div>

            {errors.items && !Array.isArray(errors.items) && (
              <p role="alert" className="mb-2 text-xs text-destructive">
                {errors.items.message}
              </p>
            )}

            <div className="space-y-4">
              {fields.map((field, index) => {
                const lineTotal = lineTotals[index] ?? 0
                const itemErrors = errors.items?.[index]

                return (
                  <div
                    key={field.id}
                    className="rounded-lg border border-border bg-muted/30 p-4"
                  >
                    <div className="mb-2 flex items-center justify-between">
                      <span className="text-xs font-medium text-muted-foreground uppercase tracking-wide">
                        Item {index + 1}
                      </span>
                      {fields.length > 1 && (
                        <Button
                          type="button"
                          variant="ghost"
                          size="sm"
                          onClick={() => remove(index)}
                          aria-label={`Remove item ${index + 1}`}
                          className="text-destructive hover:text-destructive"
                        >
                          <Trash2 className="size-3.5" aria-hidden="true" />
                          Remove
                        </Button>
                      )}
                    </div>

                    <div className="grid gap-3 sm:grid-cols-2">
                      {/* Description */}
                      <div className="sm:col-span-2 space-y-1">
                        <Label htmlFor={`item-desc-${index}`}>
                          Description{" "}
                          <span aria-hidden="true" className="text-destructive">*</span>
                        </Label>
                        <Input
                          id={`item-desc-${index}`}
                          placeholder="Item description"
                          aria-invalid={!!itemErrors?.description}
                          aria-describedby={
                            itemErrors?.description
                              ? `item-desc-error-${index}`
                              : undefined
                          }
                          {...register(`items.${index}.description`)}
                        />
                        {itemErrors?.description && (
                          <p
                            id={`item-desc-error-${index}`}
                            role="alert"
                            className="text-xs text-destructive"
                          >
                            {itemErrors.description.message}
                          </p>
                        )}
                      </div>

                      {/* Quantity */}
                      <div className="space-y-1">
                        <Label htmlFor={`item-qty-${index}`}>
                          Quantity{" "}
                          <span aria-hidden="true" className="text-destructive">*</span>
                        </Label>
                        <Input
                          id={`item-qty-${index}`}
                          type="number"
                          step="0.001"
                          min="0.001"
                          placeholder="0"
                          aria-invalid={!!itemErrors?.quantity}
                          aria-describedby={
                            itemErrors?.quantity
                              ? `item-qty-error-${index}`
                              : undefined
                          }
                          {...register(`items.${index}.quantity`)}
                        />
                        {itemErrors?.quantity && (
                          <p
                            id={`item-qty-error-${index}`}
                            role="alert"
                            className="text-xs text-destructive"
                          >
                            {itemErrors.quantity.message}
                          </p>
                        )}
                      </div>

                      {/* Unit of Measure */}
                      <div className="space-y-1">
                        <Label htmlFor={`item-uom-${index}`}>
                          Unit of Measure{" "}
                          <span aria-hidden="true" className="text-destructive">*</span>
                        </Label>
                        <Input
                          id={`item-uom-${index}`}
                          placeholder="e.g. pcs, kg, box"
                          aria-invalid={!!itemErrors?.unit_of_measure}
                          aria-describedby={
                            itemErrors?.unit_of_measure
                              ? `item-uom-error-${index}`
                              : undefined
                          }
                          {...register(`items.${index}.unit_of_measure`)}
                        />
                        {itemErrors?.unit_of_measure && (
                          <p
                            id={`item-uom-error-${index}`}
                            role="alert"
                            className="text-xs text-destructive"
                          >
                            {itemErrors.unit_of_measure.message}
                          </p>
                        )}
                      </div>

                      {/* Unit Price */}
                      <div className="space-y-1">
                        <Label htmlFor={`item-price-${index}`}>
                          Unit Price{" "}
                          <span aria-hidden="true" className="text-destructive">*</span>
                        </Label>
                        <Input
                          id={`item-price-${index}`}
                          type="number"
                          step="0.01"
                          min="0"
                          placeholder="0.00"
                          aria-invalid={!!itemErrors?.estimated_unit_price}
                          aria-describedby={
                            itemErrors?.estimated_unit_price
                              ? `item-price-error-${index}`
                              : undefined
                          }
                          {...register(`items.${index}.estimated_unit_price`)}
                        />
                        {itemErrors?.estimated_unit_price && (
                          <p
                            id={`item-price-error-${index}`}
                            role="alert"
                            className="text-xs text-destructive"
                          >
                            {itemErrors.estimated_unit_price.message}
                          </p>
                        )}
                      </div>

                      {/* Budget Code (optional) */}
                      <div className="space-y-1">
                        <Label htmlFor={`item-budget-${index}`}>
                          Budget Code{" "}
                          <span className="text-xs text-muted-foreground">(optional)</span>
                        </Label>
                        <Input
                          id={`item-budget-${index}`}
                          placeholder="e.g. OPS-2024-001"
                          {...register(`items.${index}.budget_code`)}
                        />
                      </div>
                    </div>

                    {/* Line total */}
                    <div className="mt-3 flex justify-end">
                      <span className="text-sm text-muted-foreground">
                        Line total:{" "}
                        <span className="font-semibold text-foreground tabular-nums">
                          {formatCurrency(lineTotal.toFixed(2), currency)}
                        </span>
                      </span>
                    </div>
                  </div>
                )
              })}
            </div>

            {/* Grand total */}
            <div className="mt-4 flex justify-end rounded-lg bg-muted/50 px-4 py-3">
              <span className="text-sm font-semibold">
                Estimated Total:{" "}
                <span className="tabular-nums text-base">
                  {formatCurrency(grandTotal.toFixed(2), currency)}
                </span>
              </span>
            </div>
          </section>

          <Separator />

          {/* ── File attachments ────────────────────────────────────────── */}
          <section aria-labelledby="pr-attachments-heading">
            <h3
              id="pr-attachments-heading"
              className="mb-3 text-sm font-semibold text-foreground"
            >
              Attachments{" "}
              <span className="text-xs font-normal text-muted-foreground">
                (PDF, DOCX, XLSX, PNG, JPG · max 10 MB each)
              </span>
            </h3>

            <Label
              htmlFor="pr-file-input"
              className="inline-flex cursor-pointer items-center gap-2 rounded-md border border-dashed border-border bg-muted/30 px-4 py-3 text-sm text-muted-foreground hover:bg-muted transition-colors"
            >
              <Paperclip className="size-4" aria-hidden="true" />
              Click to attach files
            </Label>
            <input
              id="pr-file-input"
              type="file"
              className="sr-only"
              multiple
              accept=".pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg"
              onChange={handleFileChange}
              aria-label="Attach supporting documents"
            />

            {fileError && (
              <p role="alert" className="mt-1 text-xs text-destructive">
                {fileError}
              </p>
            )}

            {attachedFiles.length > 0 && (
              <ul className="mt-3 space-y-1.5" aria-label="Attached files">
                {attachedFiles.map((file, i) => (
                  <li
                    key={`${file.name}-${i}`}
                    className="flex items-center justify-between rounded-md bg-muted/50 px-3 py-2 text-sm"
                  >
                    <span className="flex items-center gap-2 truncate">
                      <Paperclip className="size-3.5 shrink-0 text-muted-foreground" aria-hidden="true" />
                      <span className="truncate">{file.name}</span>
                      <span className="text-xs text-muted-foreground shrink-0">
                        ({formatFileSize(file.size)})
                      </span>
                    </span>
                    <button
                      type="button"
                      onClick={() => removeFile(i)}
                      aria-label={`Remove attachment ${file.name}`}
                      className="ml-2 shrink-0 rounded-sm p-0.5 text-muted-foreground hover:text-destructive transition-colors"
                    >
                      <X className="size-3.5" aria-hidden="true" />
                    </button>
                  </li>
                ))}
              </ul>
            )}
          </section>
        </form>

        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            onClick={handleClose}
            disabled={createPR.isPending}
          >
            Cancel
          </Button>
          <Button
            type="submit"
            form="create-pr-form"
            disabled={createPR.isPending}
          >
            {createPR.isPending ? "Creating…" : "Create Purchase Request"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
