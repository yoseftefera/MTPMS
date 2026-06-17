"use client"

/**
 * Create / Edit Tender dialog form.
 *
 * Features:
 * - React Hook Form + Zod validation (tenderSchema)
 * - All tender fields: title, description, category, tender_type,
 *   estimated_value, currency, submission_deadline
 * - Document upload: PDF/DOCX support, multiple files, max 10 MB each
 * - On create: creates draft tender then uploads documents sequentially
 * - On edit: patches changed fields then uploads any new documents
 * - Invalidates tender list and detail caches on success
 *
 * Validates: Requirements 8.1, 8.3, 22.6, 22.7
 */

import { useState } from "react"
import { useForm } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"
import { Paperclip, X } from "lucide-react"
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
import { Alert, AlertDescription } from "@/components/ui/alert"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { Separator } from "@/components/ui/separator"
import {
  tenderSchema,
  type TenderFormData,
  TENDER_TYPES,
  TENDER_CATEGORIES,
  CURRENCIES,
} from "@/lib/validations/tenders"
import {
  useCreateTender,
  useUpdateTender,
  useUploadTenderDoc,
} from "@/hooks/useTenders"
import type { TenderDetail } from "@/types/tender"

// ─── Constants ────────────────────────────────────────────────────────────────

const ALLOWED_TYPES = [
  "application/pdf",
  "application/msword",
  "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
]
const MAX_FILE_SIZE = 10 * 1024 * 1024 // 10 MB

// ─── Helpers ──────────────────────────────────────────────────────────────────

function formatFileSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

function toDatetimeLocal(iso: string): string {
  if (!iso) return ""
  // Trim seconds/ms for datetime-local input
  return iso.slice(0, 16)
}

// ─── Props ────────────────────────────────────────────────────────────────────

interface TenderFormProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  /** If provided, the form runs in edit mode */
  tender?: TenderDetail
  onSuccess?: () => void
}

// ─── Component ────────────────────────────────────────────────────────────────

export function TenderForm({
  open,
  onOpenChange,
  tender,
  onSuccess,
}: TenderFormProps) {
  const isEditing = Boolean(tender)

  const createTender = useCreateTender()
  const updateTender = useUpdateTender()
  const uploadDoc = useUploadTenderDoc()

  const [attachedFiles, setAttachedFiles] = useState<File[]>([])
  const [fileError, setFileError] = useState<string | null>(null)
  const [successMessage, setSuccessMessage] = useState<string | null>(null)

  const {
    register,
    handleSubmit,
    setValue,
    watch,
    reset,
    formState: { errors },
  } = useForm<TenderFormData>({
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    resolver: zodResolver(tenderSchema) as any,
    defaultValues: tender
      ? {
          title: tender.title,
          description: tender.description,
          category: tender.category,
          tender_type: tender.tender_type,
          estimated_value: tender.estimated_value,
          submission_deadline: toDatetimeLocal(tender.submission_deadline),
          currency: tender.currency ?? "USD",
        }
      : {
          title: "",
          description: "",
          category: "",
          tender_type: "open",
          estimated_value: "",
          submission_deadline: "",
          currency: "USD",
        },
  })

  const currency = watch("currency")
  const tenderType = watch("tender_type")
  const category = watch("category")

  // ── File handling ────────────────────────────────────────────────────────────

  function handleFileChange(e: React.ChangeEvent<HTMLInputElement>) {
    setFileError(null)
    const files = Array.from(e.target.files ?? [])
    const validated: File[] = []

    for (const file of files) {
      if (!ALLOWED_TYPES.includes(file.type)) {
        setFileError(
          `"${file.name}" is not an allowed file type. Allowed: PDF, DOCX.`,
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
    e.target.value = ""
  }

  function removeFile(index: number) {
    setAttachedFiles((prev) => prev.filter((_, i) => i !== index))
  }

  // ── Submit ───────────────────────────────────────────────────────────────────

  const onSubmit = handleSubmit(async (data) => {
    setSuccessMessage(null)

    try {
      let tenderId: string

      if (isEditing && tender) {
        const result = await updateTender.mutateAsync({
          id: tender.id,
          payload: data,
        })
        tenderId = result.data?.id ?? tender.id
      } else {
        const result = await createTender.mutateAsync(data)
        tenderId = result.data?.id ?? ""
      }

      // Upload documents sequentially after tender is created/updated
      for (const file of attachedFiles) {
        await uploadDoc.mutateAsync({ tenderId, file })
      }

      setSuccessMessage(
        isEditing
          ? "Tender updated successfully."
          : "Tender created successfully.",
      )

      setTimeout(() => {
        handleClose()
        onSuccess?.()
      }, 800)
    } catch {
      // Error is surfaced via mutation state below
    }
  })

  function handleClose() {
    reset()
    setAttachedFiles([])
    setFileError(null)
    setSuccessMessage(null)
    onOpenChange(false)
  }

  const isPending =
    createTender.isPending || updateTender.isPending || uploadDoc.isPending

  const mutationError = isEditing
    ? updateTender.isError
    : createTender.isError

  const errorMessage = (
    (isEditing ? updateTender.error : createTender.error) as {
      response?: { data?: { message?: string } }
    }
  )?.response?.data?.message

  // ─── Render ───────────────────────────────────────────────────────────────────

  return (
    <Dialog open={open} onOpenChange={handleClose}>
      <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>
            {isEditing ? "Edit Tender" : "Create Tender"}
          </DialogTitle>
          <DialogDescription>
            {isEditing
              ? "Update the tender details and attach additional documents."
              : "Fill in the tender details. You can attach specification documents below."}
          </DialogDescription>
        </DialogHeader>

        {successMessage && (
          <Alert role="status">
            <AlertDescription>{successMessage}</AlertDescription>
          </Alert>
        )}

        {mutationError && !successMessage && (
          <Alert variant="destructive" role="alert">
            <AlertDescription>
              {errorMessage ?? "Failed to save tender. Please try again."}
            </AlertDescription>
          </Alert>
        )}

        <form id="tender-form" onSubmit={onSubmit} noValidate className="space-y-5">
          {/* Title */}
          <div className="space-y-1.5">
            <Label htmlFor="tender-title">
              Title <span aria-hidden="true" className="text-destructive">*</span>
            </Label>
            <Input
              id="tender-title"
              placeholder="e.g. Supply of Office Furniture"
              aria-invalid={!!errors.title}
              aria-describedby={errors.title ? "tender-title-error" : undefined}
              {...register("title")}
            />
            {errors.title && (
              <p id="tender-title-error" role="alert" className="text-xs text-destructive">
                {errors.title.message}
              </p>
            )}
          </div>

          {/* Description */}
          <div className="space-y-1.5">
            <Label htmlFor="tender-description">
              Description <span aria-hidden="true" className="text-destructive">*</span>
            </Label>
            <Textarea
              id="tender-description"
              placeholder="Detailed description of goods or services required…"
              rows={4}
              aria-invalid={!!errors.description}
              aria-describedby={errors.description ? "tender-desc-error" : undefined}
              {...register("description")}
            />
            {errors.description && (
              <p id="tender-desc-error" role="alert" className="text-xs text-destructive">
                {errors.description.message}
              </p>
            )}
          </div>

          {/* Category + Tender Type (inline) */}
          <div className="grid gap-4 sm:grid-cols-2">
            <div className="space-y-1.5">
              <Label htmlFor="tender-category">
                Category <span aria-hidden="true" className="text-destructive">*</span>
              </Label>
              <Select
                value={category}
                onValueChange={(val) =>
                  setValue("category", val, { shouldValidate: true })
                }
              >
                <SelectTrigger
                  id="tender-category"
                  aria-label="Select category"
                  aria-invalid={!!errors.category}
                >
                  <SelectValue placeholder="Select category…" />
                </SelectTrigger>
                <SelectContent>
                  {TENDER_CATEGORIES.map((cat) => (
                    <SelectItem key={cat} value={cat}>
                      {cat}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              {errors.category && (
                <p role="alert" className="text-xs text-destructive">
                  {errors.category.message}
                </p>
              )}
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="tender-type">
                Tender Type <span aria-hidden="true" className="text-destructive">*</span>
              </Label>
              <Select
                value={tenderType}
                onValueChange={(val) =>
                  setValue(
                    "tender_type",
                    val as "open" | "restricted" | "single_source",
                    { shouldValidate: true },
                  )
                }
              >
                <SelectTrigger
                  id="tender-type"
                  aria-label="Select tender type"
                  aria-invalid={!!errors.tender_type}
                >
                  <SelectValue placeholder="Select type…" />
                </SelectTrigger>
                <SelectContent>
                  {TENDER_TYPES.map((t) => (
                    <SelectItem key={t.value} value={t.value}>
                      {t.label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              {errors.tender_type && (
                <p role="alert" className="text-xs text-destructive">
                  {errors.tender_type.message}
                </p>
              )}
            </div>
          </div>

          {/* Estimated Value + Currency (inline) */}
          <div className="grid gap-4 sm:grid-cols-2">
            <div className="space-y-1.5">
              <Label htmlFor="tender-value">
                Estimated Value <span aria-hidden="true" className="text-destructive">*</span>
              </Label>
              <Input
                id="tender-value"
                type="number"
                step="0.01"
                min="0.01"
                placeholder="0.00"
                aria-invalid={!!errors.estimated_value}
                aria-describedby={
                  errors.estimated_value ? "tender-value-error" : undefined
                }
                {...register("estimated_value")}
              />
              {errors.estimated_value && (
                <p
                  id="tender-value-error"
                  role="alert"
                  className="text-xs text-destructive"
                >
                  {errors.estimated_value.message}
                </p>
              )}
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="tender-currency">Currency</Label>
              <Select
                value={currency}
                onValueChange={(val) =>
                  setValue("currency", val, { shouldValidate: true })
                }
              >
                <SelectTrigger id="tender-currency" aria-label="Select currency">
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

          {/* Submission Deadline */}
          <div className="space-y-1.5">
            <Label htmlFor="tender-deadline">
              Submission Deadline{" "}
              <span aria-hidden="true" className="text-destructive">*</span>
            </Label>
            <Input
              id="tender-deadline"
              type="datetime-local"
              aria-invalid={!!errors.submission_deadline}
              aria-describedby={
                errors.submission_deadline ? "tender-deadline-error" : undefined
              }
              {...register("submission_deadline")}
            />
            {errors.submission_deadline && (
              <p
                id="tender-deadline-error"
                role="alert"
                className="text-xs text-destructive"
              >
                {errors.submission_deadline.message}
              </p>
            )}
          </div>

          <Separator />

          {/* Document Upload */}
          <section aria-labelledby="tender-docs-heading">
            <h3
              id="tender-docs-heading"
              className="mb-3 text-sm font-semibold text-foreground"
            >
              Specification Documents{" "}
              <span className="text-xs font-normal text-muted-foreground">
                (PDF, DOCX · max 10 MB each)
              </span>
            </h3>

            <Label
              htmlFor="tender-file-input"
              className="inline-flex cursor-pointer items-center gap-2 rounded-md border border-dashed border-border bg-muted/30 px-4 py-3 text-sm text-muted-foreground hover:bg-muted transition-colors"
            >
              <Paperclip className="size-4" aria-hidden="true" />
              Click to attach documents
            </Label>
            <input
              id="tender-file-input"
              type="file"
              className="sr-only"
              multiple
              accept=".pdf,.doc,.docx"
              onChange={handleFileChange}
              aria-label="Attach specification documents"
            />

            {fileError && (
              <p role="alert" className="mt-1 text-xs text-destructive">
                {fileError}
              </p>
            )}

            {/* Existing documents (edit mode) */}
            {isEditing && tender?.documents && tender.documents.length > 0 && (
              <div className="mt-3">
                <p className="mb-1.5 text-xs text-muted-foreground">
                  Already attached ({tender.documents.length}):
                </p>
                <ul className="space-y-1" aria-label="Existing documents">
                  {tender.documents.map((doc) => (
                    <li
                      key={doc.id}
                      className="flex items-center gap-2 rounded-md bg-muted/30 px-3 py-1.5 text-xs text-muted-foreground"
                    >
                      <Paperclip className="size-3 shrink-0" aria-hidden="true" />
                      <span className="truncate">{doc.file_name}</span>
                    </li>
                  ))}
                </ul>
              </div>
            )}

            {attachedFiles.length > 0 && (
              <ul className="mt-3 space-y-1.5" aria-label="New files to upload">
                {attachedFiles.map((file, i) => (
                  <li
                    key={`${file.name}-${i}`}
                    className="flex items-center justify-between rounded-md bg-muted/50 px-3 py-2 text-sm"
                  >
                    <span className="flex items-center gap-2 truncate">
                      <Paperclip
                        className="size-3.5 shrink-0 text-muted-foreground"
                        aria-hidden="true"
                      />
                      <span className="truncate">{file.name}</span>
                      <span className="text-xs text-muted-foreground shrink-0">
                        ({formatFileSize(file.size)})
                      </span>
                    </span>
                    <button
                      type="button"
                      onClick={() => removeFile(i)}
                      aria-label={`Remove ${file.name}`}
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
            disabled={isPending}
          >
            Cancel
          </Button>
          <Button type="submit" form="tender-form" disabled={isPending}>
            {isPending
              ? isEditing
                ? "Saving…"
                : "Creating…"
              : isEditing
                ? "Save Changes"
                : "Create Tender"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
