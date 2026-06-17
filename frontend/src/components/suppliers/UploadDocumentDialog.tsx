"use client"

/**
 * Dialog to upload a compliance document for a supplier.
 * Validates: Requirements 7.10, 23.1
 */

import { useRef } from "react"
import { useForm } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"
import { Upload } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Label } from "@/components/ui/label"
import { Input } from "@/components/ui/input"
import { Alert, AlertDescription } from "@/components/ui/alert"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from "@/components/ui/dialog"
import {
  uploadDocumentSchema,
  type UploadDocumentFormData,
  SUPPLIER_DOCUMENT_TYPES,
  DOCUMENT_TYPE_LABELS,
} from "@/lib/validations/suppliers"
import { useUploadSupplierDoc } from "@/hooks/useSuppliers"
import type { SupplierDocument } from "@/types/models.types"

interface UploadDocumentDialogProps {
  supplierId: string
  open: boolean
  onOpenChange: (open: boolean) => void
  onSuccess?: () => void
}

export function UploadDocumentDialog({
  supplierId,
  open,
  onOpenChange,
  onSuccess,
}: UploadDocumentDialogProps) {
  const upload = useUploadSupplierDoc()
  const fileInputRef = useRef<HTMLInputElement>(null)

  const {
    register,
    handleSubmit,
    setValue,
    watch,
    reset,
    formState: { errors },
  } = useForm<UploadDocumentFormData>({
    resolver: zodResolver(uploadDocumentSchema),
  })

  const selectedDocType = watch("document_type")

  async function onSubmit(data: UploadDocumentFormData) {
    const file = fileInputRef.current?.files?.[0]
    if (!file) return

    try {
      await upload.mutateAsync({
        supplierId,
        file,
        documentType: data.document_type as SupplierDocument["document_type"],
        expiresAt: data.expires_at ?? null,
      })
      reset()
      if (fileInputRef.current) fileInputRef.current.value = ""
      onOpenChange(false)
      onSuccess?.()
    } catch {
      // error displayed via mutation state
    }
  }

  function handleClose() {
    reset()
    if (fileInputRef.current) fileInputRef.current.value = ""
    onOpenChange(false)
  }

  return (
    <Dialog open={open} onOpenChange={handleClose}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Upload Compliance Document</DialogTitle>
          <DialogDescription>
            Upload a new compliance document. Accepted formats: PDF, DOCX, PNG, JPG. Max 10 MB.
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={handleSubmit(onSubmit)} id="upload-doc-form" noValidate>
          <div className="space-y-4 py-2">
            {/* Document type */}
            <div className="space-y-1.5">
              <Label htmlFor="doc-type">
                Document Type <span aria-hidden="true" className="text-destructive">*</span>
              </Label>
              <Select
                value={selectedDocType}
                onValueChange={(val) =>
                  setValue("document_type", val as SupplierDocument["document_type"], {
                    shouldValidate: true,
                  })
                }
              >
                <SelectTrigger
                  id="doc-type"
                  aria-required="true"
                  aria-describedby={errors.document_type ? "doc-type-error" : undefined}
                >
                  <SelectValue placeholder="Select document type…" />
                </SelectTrigger>
                <SelectContent>
                  {SUPPLIER_DOCUMENT_TYPES.map((type) => (
                    <SelectItem key={type} value={type}>
                      {DOCUMENT_TYPE_LABELS[type]}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              {errors.document_type && (
                <p id="doc-type-error" className="text-sm text-destructive" role="alert">
                  {errors.document_type.message}
                </p>
              )}
            </div>

            {/* File input */}
            <div className="space-y-1.5">
              <Label htmlFor="doc-file">
                File <span aria-hidden="true" className="text-destructive">*</span>
              </Label>
              <Input
                id="doc-file"
                type="file"
                accept=".pdf,.docx,.xlsx,.png,.jpg,.jpeg"
                ref={fileInputRef}
                aria-required="true"
                className="cursor-pointer"
              />
              <p className="text-xs text-muted-foreground">
                PDF, DOCX, XLSX, PNG, JPG — max 10 MB
              </p>
            </div>

            {/* Expiry date (optional) */}
            <div className="space-y-1.5">
              <Label htmlFor="doc-expires">Expiry Date (optional)</Label>
              <Input
                id="doc-expires"
                type="date"
                aria-describedby={errors.expires_at ? "doc-expires-error" : undefined}
                {...register("expires_at")}
              />
              {errors.expires_at && (
                <p id="doc-expires-error" className="text-sm text-destructive" role="alert">
                  {errors.expires_at.message}
                </p>
              )}
            </div>
          </div>

          {upload.isError && (
            <Alert variant="destructive" role="alert" className="mt-2">
              <AlertDescription>
                Failed to upload document. Please check the file and try again.
              </AlertDescription>
            </Alert>
          )}
        </form>

        <DialogFooter className="gap-2">
          <Button
            type="button"
            variant="outline"
            onClick={handleClose}
            disabled={upload.isPending}
          >
            Cancel
          </Button>
          <Button
            type="submit"
            form="upload-doc-form"
            disabled={upload.isPending}
          >
            <Upload className="size-4" aria-hidden="true" />
            {upload.isPending ? "Uploading…" : "Upload Document"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
