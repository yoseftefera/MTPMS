"use client"

/**
 * Supplier-facing Open Tender Detail + Bid Submission page.
 *
 * Accessible at /tenders/open/[id].
 * Shows tender details, deadline countdown, and bid submission/revision form.
 *
 * Features:
 * - Tender info: reference, title, category, type, value, deadline
 * - Deadline countdown (days / hours / minutes remaining)
 * - Specification documents list
 * - Bid submission form (React Hook Form + Zod):
 *     fields: total_amount, currency, delivery_days, technical_notes
 * - File upload for bid documents (PDF, DOCX, XLSX, PNG, JPG — max 10 MB)
 * - Prevents submission after deadline
 * - Shows existing bid if already submitted (revision allowed before deadline)
 * - Loading skeleton + error state
 *
 * Validates: Requirements 8.1, 8.3, 8.4, 8.5, 22.6
 */

import { use, useState, useEffect } from "react"
import Link from "next/link"
import { useForm } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"
import { motion } from "framer-motion"
import {
  ArrowLeft,
  Clock,
  FileText,
  Download,
  Paperclip,
  X,
  RefreshCw,
  CheckCircle2,
} from "lucide-react"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Textarea } from "@/components/ui/textarea"
import { Badge } from "@/components/ui/badge"
import { Skeleton } from "@/components/ui/skeleton"
import { Alert, AlertDescription } from "@/components/ui/alert"
import { Separator } from "@/components/ui/separator"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import {
  useOpenTender,
  useSubmitBid,
  useUpdateBid,
  useUploadBidDoc,
} from "@/hooks/useTenders"
import { bidSchema, type BidFormData, CURRENCIES } from "@/lib/validations/tenders"
import { formatCurrency } from "@/lib/utils"
import type { TenderDocument } from "@/types/tender"

// ─── Constants ────────────────────────────────────────────────────────────────

const ALLOWED_MIME_TYPES = [
  "application/pdf",
  "application/msword",
  "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
  "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
  "image/png",
  "image/jpeg",
]
const MAX_FILE_SIZE = 10 * 1024 * 1024 // 10 MB

// ─── Countdown ────────────────────────────────────────────────────────────────

interface CountdownState {
  days: number
  hours: number
  minutes: number
  seconds: number
  expired: boolean
  urgent: boolean // < 24 h
}

function useCountdown(deadline: string): CountdownState {
  const [state, setState] = useState<CountdownState>(() =>
    computeCountdown(deadline),
  )

  useEffect(() => {
    const timer = setInterval(() => {
      setState(computeCountdown(deadline))
    }, 1000)
    return () => clearInterval(timer)
  }, [deadline])

  return state
}

function computeCountdown(deadline: string): CountdownState {
  const diff = new Date(deadline).getTime() - Date.now()
  if (diff <= 0) {
    return { days: 0, hours: 0, minutes: 0, seconds: 0, expired: true, urgent: false }
  }
  const days = Math.floor(diff / (1000 * 60 * 60 * 24))
  const hours = Math.floor((diff % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60))
  const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60))
  const seconds = Math.floor((diff % (1000 * 60)) / 1000)
  return { days, hours, minutes, seconds, expired: false, urgent: diff < 24 * 60 * 60 * 1000 }
}

// ─── Helpers ──────────────────────────────────────────────────────────────────

function formatDateTime(iso: string): string {
  return new Intl.DateTimeFormat("en-US", {
    dateStyle: "medium",
    timeStyle: "short",
  }).format(new Date(iso))
}

function formatFileSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

// ─── Countdown display ────────────────────────────────────────────────────────

function CountdownDisplay({ deadline }: { deadline: string }) {
  const { days, hours, minutes, seconds, expired, urgent } = useCountdown(deadline)

  if (expired) {
    return (
      <div
        className="flex items-center gap-2 rounded-lg bg-destructive/10 px-4 py-3 text-destructive"
        role="status"
        aria-live="polite"
        aria-label="Deadline expired"
      >
        <Clock className="size-4 shrink-0" aria-hidden="true" />
        <span className="text-sm font-semibold">Deadline has passed — submission closed</span>
      </div>
    )
  }

  const colorClass = urgent
    ? "bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-800 text-amber-700 dark:text-amber-300"
    : "bg-emerald-50 dark:bg-emerald-950/30 border border-emerald-200 dark:border-emerald-800 text-emerald-700 dark:text-emerald-300"

  return (
    <div
      className={`flex items-center gap-3 rounded-lg px-4 py-3 ${colorClass}`}
      role="timer"
      aria-live="polite"
      aria-label={`Time remaining: ${days} days, ${hours} hours, ${minutes} minutes, ${seconds} seconds`}
    >
      <Clock className="size-4 shrink-0" aria-hidden="true" />
      <div className="flex items-center gap-3 text-sm font-semibold tabular-nums">
        {days > 0 && (
          <span>
            <span className="text-lg">{days}</span>
            <span className="ml-1 text-xs font-normal opacity-80">d</span>
          </span>
        )}
        <span>
          <span className="text-lg">{String(hours).padStart(2, "0")}</span>
          <span className="ml-1 text-xs font-normal opacity-80">h</span>
        </span>
        <span>
          <span className="text-lg">{String(minutes).padStart(2, "0")}</span>
          <span className="ml-1 text-xs font-normal opacity-80">m</span>
        </span>
        <span>
          <span className="text-lg">{String(seconds).padStart(2, "0")}</span>
          <span className="ml-1 text-xs font-normal opacity-80">s</span>
        </span>
        <span className="text-xs font-normal opacity-80">remaining</span>
      </div>
    </div>
  )
}

// ─── Documents list ───────────────────────────────────────────────────────────

function DocumentsList({ documents }: { documents?: TenderDocument[] }) {
  if (!documents || documents.length === 0) {
    return (
      <p className="text-sm text-muted-foreground">No documents attached.</p>
    )
  }
  return (
    <ul className="space-y-2" aria-label="Tender specification documents">
      {documents.map((doc) => (
        <li
          key={doc.id}
          className="flex items-center justify-between rounded-md border border-border bg-muted/30 px-4 py-2.5"
        >
          <span className="flex items-center gap-2 text-sm">
            <FileText
              className="size-4 shrink-0 text-muted-foreground"
              aria-hidden="true"
            />
            <span className="truncate">{doc.file_name}</span>
          </span>
          <a
            href={doc.file_path}
            target="_blank"
            rel="noopener noreferrer"
            className="ml-4 shrink-0 inline-flex items-center gap-1.5 rounded-sm text-xs text-primary hover:underline underline-offset-2"
            aria-label={`Download ${doc.file_name}`}
          >
            <Download className="size-3.5" aria-hidden="true" />
            Download
          </a>
        </li>
      ))}
    </ul>
  )
}

// ─── Loading skeleton ─────────────────────────────────────────────────────────

function PageSkeleton() {
  return (
    <div className="space-y-6">
      <Skeleton className="h-5 w-32" />
      <Skeleton className="h-8 w-64" />
      <Skeleton className="h-16 w-full rounded-lg" />
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {Array.from({ length: 4 }).map((_, i) => (
          <Skeleton key={i} className="h-20 rounded-xl" />
        ))}
      </div>
      <Skeleton className="h-80 rounded-xl" />
    </div>
  )
}

// ─── Framer Motion ────────────────────────────────────────────────────────────

const fadeIn = {
  hidden: { opacity: 0, y: 8 },
  visible: {
    opacity: 1,
    y: 0,
    transition: { duration: 0.25, ease: "easeOut" as const },
  },
}

// ─── Page ─────────────────────────────────────────────────────────────────────

export default function OpenTenderDetailPage({
  params,
}: {
  params: Promise<{ id: string }>
}) {
  const { id } = use(params)

  const { data, isLoading, isError, refetch } = useOpenTender(id)
  const submitBid = useSubmitBid()
  const updateBid = useUpdateBid()
  const uploadBidDoc = useUploadBidDoc()

  const [attachedFiles, setAttachedFiles] = useState<File[]>([])
  const [fileError, setFileError] = useState<string | null>(null)
  const [submitSuccess, setSubmitSuccess] = useState(false)

  const tender = data?.data
  const existingBid = tender?.my_bid ?? null
  const isRevision = Boolean(existingBid)
  const deadlineExpired = tender
    ? new Date(tender.submission_deadline).getTime() <= Date.now()
    : false

  const {
    register,
    handleSubmit,
    setValue,
    watch,
    reset,
    formState: { errors },
  } = useForm<BidFormData>({
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    resolver: zodResolver(bidSchema) as any,
    defaultValues: existingBid
      ? {
          total_amount: existingBid.total_amount,
          currency: existingBid.currency ?? "USD",
          delivery_days: existingBid.delivery_days,
          technical_notes: existingBid.technical_notes ?? "",
        }
      : {
          total_amount: "",
          currency: "USD",
          delivery_days: undefined,
          technical_notes: "",
        },
  })

  // Reset form when tender/bid data loads
  useEffect(() => {
    if (existingBid) {
      reset({
        total_amount: existingBid.total_amount,
        currency: existingBid.currency ?? "USD",
        delivery_days: existingBid.delivery_days,
        technical_notes: existingBid.technical_notes ?? "",
      })
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [existingBid?.id])

  const currency = watch("currency")

  // ── File handling ────────────────────────────────────────────────────────────

  function handleFileChange(e: React.ChangeEvent<HTMLInputElement>) {
    setFileError(null)
    const files = Array.from(e.target.files ?? [])
    const validated: File[] = []

    for (const file of files) {
      if (!ALLOWED_MIME_TYPES.includes(file.type)) {
        setFileError(`"${file.name}" is not an allowed file type.`)
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
    if (!tender) return
    setSubmitSuccess(false)

    try {
      let bidId: string

      if (isRevision && existingBid) {
        await updateBid.mutateAsync({
          tenderId: tender.id,
          bidId: existingBid.id,
          payload: data,
        })
        bidId = existingBid.id
      } else {
        const result = await submitBid.mutateAsync({
          tenderId: tender.id,
          payload: data,
        })
        bidId = result.data?.id ?? ""
      }

      // Upload bid documents
      for (const file of attachedFiles) {
        await uploadBidDoc.mutateAsync({
          tenderId: tender.id,
          bidId,
          file,
        })
      }

      setAttachedFiles([])
      setSubmitSuccess(true)
    } catch {
      // surfaced via mutation state
    }
  })

  const isPending =
    submitBid.isPending || updateBid.isPending || uploadBidDoc.isPending

  const mutationError = isRevision ? updateBid.isError : submitBid.isError
  const errorMessage = (
    (isRevision ? updateBid.error : submitBid.error) as {
      response?: { data?: { message?: string } }
    }
  )?.response?.data?.message

  // ── Loading / error ──────────────────────────────────────────────────────────

  if (isLoading) return <PageSkeleton />

  if (isError || !tender) {
    return (
      <div className="flex flex-col items-center gap-4 py-16">
        <p className="text-sm text-muted-foreground">Failed to load tender.</p>
        <Button variant="outline" size="sm" onClick={() => refetch()}>
          <RefreshCw className="size-3.5" aria-hidden="true" />
          Retry
        </Button>
      </div>
    )
  }

  // ── Render ───────────────────────────────────────────────────────────────────

  return (
    <motion.div
      className="space-y-6"
      initial="hidden"
      animate="visible"
      variants={fadeIn}
    >
      {/* Back link */}
      <Link
        href="/tenders/open"
        className="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground transition-colors"
      >
        <ArrowLeft className="size-4" aria-hidden="true" />
        Back to Open Tenders
      </Link>

      {/* Header */}
      <div className="space-y-1">
        <div className="flex flex-wrap items-center gap-3">
          <h1 className="text-2xl font-semibold tracking-tight font-mono">
            {tender.reference_number}
          </h1>
          <Badge variant="outline" className="capitalize text-xs">
            {tender.tender_type.replace(/_/g, " ")}
          </Badge>
          {isRevision && (
            <Badge variant="secondary" className="text-xs">
              Bid Submitted
            </Badge>
          )}
        </div>
        <p className="text-lg text-muted-foreground">{tender.title}</p>
      </div>

      {/* Deadline countdown */}
      <CountdownDisplay deadline={tender.submission_deadline} />

      {/* Info cards */}
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <Card className="p-4">
          <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
            Category
          </p>
          <p className="mt-1 text-base font-semibold">{tender.category}</p>
        </Card>
        <Card className="p-4">
          <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
            Estimated Value
          </p>
          <p className="mt-1 text-base font-semibold tabular-nums">
            {formatCurrency(tender.estimated_value, tender.currency ?? "USD")}
          </p>
        </Card>
        <Card className="p-4">
          <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
            Deadline
          </p>
          <p
            className={`mt-1 text-sm font-semibold ${deadlineExpired ? "text-destructive" : ""}`}
          >
            {formatDateTime(tender.submission_deadline)}
          </p>
        </Card>
        <Card className="p-4">
          <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
            Documents
          </p>
          <p className="mt-1 text-base font-semibold">
            {tender.documents?.length ?? 0}
          </p>
        </Card>
      </div>

      {/* Description */}
      <Card>
        <CardHeader className="pb-2">
          <CardTitle className="text-sm">Description</CardTitle>
        </CardHeader>
        <CardContent>
          <p className="text-sm text-muted-foreground whitespace-pre-wrap">
            {tender.description}
          </p>
        </CardContent>
      </Card>

      {/* Specification documents */}
      <section aria-labelledby="spec-docs-heading">
        <h2
          id="spec-docs-heading"
          className="mb-3 text-base font-semibold"
        >
          Specification Documents{" "}
          <span className="text-sm font-normal text-muted-foreground">
            ({tender.documents?.length ?? 0})
          </span>
        </h2>
        <DocumentsList documents={tender.documents} />
      </section>

      <Separator />

      {/* Bid submission form */}
      <section aria-labelledby="bid-form-heading">
        <h2 id="bid-form-heading" className="mb-4 text-base font-semibold">
          {deadlineExpired
            ? "Bid Submission Closed"
            : isRevision
              ? "Revise Your Bid"
              : "Submit Your Bid"}
        </h2>

        {deadlineExpired ? (
          <Alert role="status">
            <AlertDescription>
              The submission deadline has passed. Bid submissions are closed for
              this tender.
            </AlertDescription>
          </Alert>
        ) : (
          <Card>
            <CardContent className="pt-6">
              {submitSuccess && (
                <Alert className="mb-4 border-emerald-200 bg-emerald-50 dark:bg-emerald-950/30" role="status">
                  <CheckCircle2 className="size-4 text-emerald-600" aria-hidden="true" />
                  <AlertDescription className="text-emerald-700 dark:text-emerald-300">
                    {isRevision
                      ? "Your bid has been updated successfully."
                      : "Your bid has been submitted successfully."}
                  </AlertDescription>
                </Alert>
              )}

              {mutationError && !submitSuccess && (
                <Alert variant="destructive" className="mb-4" role="alert">
                  <AlertDescription>
                    {errorMessage ?? "Failed to submit bid. Please try again."}
                  </AlertDescription>
                </Alert>
              )}

              <form
                id="bid-form"
                onSubmit={onSubmit}
                noValidate
                className="space-y-5"
              >
                {/* Total amount + currency */}
                <div className="grid gap-4 sm:grid-cols-2">
                  <div className="space-y-1.5">
                    <Label htmlFor="bid-amount">
                      Total Bid Amount{" "}
                      <span aria-hidden="true" className="text-destructive">
                        *
                      </span>
                    </Label>
                    <Input
                      id="bid-amount"
                      type="number"
                      step="0.01"
                      min="0.01"
                      placeholder="0.00"
                      aria-invalid={!!errors.total_amount}
                      aria-describedby={
                        errors.total_amount ? "bid-amount-error" : undefined
                      }
                      {...register("total_amount")}
                    />
                    {errors.total_amount && (
                      <p
                        id="bid-amount-error"
                        role="alert"
                        className="text-xs text-destructive"
                      >
                        {errors.total_amount.message}
                      </p>
                    )}
                  </div>

                  <div className="space-y-1.5">
                    <Label htmlFor="bid-currency">Currency</Label>
                    <Select
                      value={currency}
                      onValueChange={(v) =>
                        setValue("currency", v, { shouldValidate: true })
                      }
                    >
                      <SelectTrigger id="bid-currency" aria-label="Select currency">
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

                {/* Delivery days */}
                <div className="space-y-1.5">
                  <Label htmlFor="bid-delivery-days">
                    Delivery Days{" "}
                    <span aria-hidden="true" className="text-destructive">
                      *
                    </span>
                  </Label>
                  <Input
                    id="bid-delivery-days"
                    type="number"
                    min="1"
                    max="3650"
                    placeholder="e.g. 30"
                    aria-invalid={!!errors.delivery_days}
                    aria-describedby={
                      errors.delivery_days ? "bid-delivery-error" : undefined
                    }
                    {...register("delivery_days", { valueAsNumber: true })}
                  />
                  {errors.delivery_days && (
                    <p
                      id="bid-delivery-error"
                      role="alert"
                      className="text-xs text-destructive"
                    >
                      {errors.delivery_days.message}
                    </p>
                  )}
                </div>

                {/* Technical notes */}
                <div className="space-y-1.5">
                  <Label htmlFor="bid-notes">Technical Notes</Label>
                  <Textarea
                    id="bid-notes"
                    placeholder="Describe your technical approach, qualifications, or any relevant notes…"
                    rows={4}
                    aria-invalid={!!errors.technical_notes}
                    aria-describedby={
                      errors.technical_notes ? "bid-notes-error" : undefined
                    }
                    {...register("technical_notes")}
                  />
                  {errors.technical_notes && (
                    <p
                      id="bid-notes-error"
                      role="alert"
                      className="text-xs text-destructive"
                    >
                      {errors.technical_notes.message}
                    </p>
                  )}
                </div>

                <Separator />

                {/* Bid document upload */}
                <section aria-labelledby="bid-docs-heading">
                  <h3
                    id="bid-docs-heading"
                    className="mb-3 text-sm font-semibold"
                  >
                    Supporting Documents{" "}
                    <span className="text-xs font-normal text-muted-foreground">
                      (PDF, DOCX, XLSX, PNG, JPG · max 10 MB each)
                    </span>
                  </h3>

                  {/* Existing bid documents */}
                  {isRevision &&
                    existingBid?.documents &&
                    existingBid.documents.length > 0 && (
                      <div className="mb-3">
                        <p className="mb-2 text-xs text-muted-foreground">
                          Previously uploaded ({existingBid.documents.length}):
                        </p>
                        <ul
                          className="space-y-1.5"
                          aria-label="Previously uploaded bid documents"
                        >
                          {existingBid.documents.map((doc) => (
                            <li
                              key={doc.id}
                              className="flex items-center gap-2 rounded-md bg-muted/30 px-3 py-1.5 text-xs text-muted-foreground"
                            >
                              <Paperclip
                                className="size-3 shrink-0"
                                aria-hidden="true"
                              />
                              <span className="truncate">{doc.file_name}</span>
                            </li>
                          ))}
                        </ul>
                      </div>
                    )}

                  <Label
                    htmlFor="bid-file-input"
                    className="inline-flex cursor-pointer items-center gap-2 rounded-md border border-dashed border-border bg-muted/30 px-4 py-3 text-sm text-muted-foreground hover:bg-muted transition-colors"
                  >
                    <Paperclip className="size-4" aria-hidden="true" />
                    Click to attach documents
                  </Label>
                  <input
                    id="bid-file-input"
                    type="file"
                    className="sr-only"
                    multiple
                    accept=".pdf,.doc,.docx,.xlsx,.png,.jpg,.jpeg"
                    onChange={handleFileChange}
                    aria-label="Attach bid documents"
                  />

                  {fileError && (
                    <p role="alert" className="mt-2 text-xs text-destructive">
                      {fileError}
                    </p>
                  )}

                  {attachedFiles.length > 0 && (
                    <ul
                      className="mt-3 space-y-1.5"
                      aria-label="Files to upload with bid"
                    >
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

                <div className="flex justify-end pt-2">
                  <Button
                    type="submit"
                    form="bid-form"
                    disabled={isPending}
                    aria-label={
                      isRevision ? "Update bid" : "Submit bid"
                    }
                  >
                    {isPending
                      ? isRevision
                        ? "Updating…"
                        : "Submitting…"
                      : isRevision
                        ? "Update Bid"
                        : "Submit Bid"}
                  </Button>
                </div>
              </form>
            </CardContent>
          </Card>
        )}
      </section>
    </motion.div>
  )
}
