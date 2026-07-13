"use client"

/**
 * RecordPaymentDialog — modal form for Finance_Officer to record a payment.
 *
 * Fields: amount_paid, payment_method (select), payment_reference (optional)
 *
 * Validates: Requirements 14.6, 14.8, 22.6
 */

import { useForm } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from "@/components/ui/dialog"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { Alert, AlertDescription } from "@/components/ui/alert"
import {
  recordPaymentSchema,
  PAYMENT_METHODS,
  type RecordPaymentFormData,
} from "@/lib/validations/invoices"
import { useRecordPayment } from "@/hooks/usePayments"

// ─── Props ────────────────────────────────────────────────────────────────────

interface RecordPaymentDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  /** Payment record ID to record against */
  paymentId: string
  /** Display info for context */
  invoiceNumber?: string
  currency?: string
  onSuccess: () => void
}

// ─── Component ────────────────────────────────────────────────────────────────

export function RecordPaymentDialog({
  open,
  onOpenChange,
  paymentId,
  invoiceNumber,
  currency = "USD",
  onSuccess,
}: RecordPaymentDialogProps) {
  const recordPayment = useRecordPayment()

  const {
    register,
    handleSubmit,
    reset,
    setValue,
    watch,
    formState: { errors },
  } = useForm<RecordPaymentFormData>({
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    resolver: zodResolver(recordPaymentSchema) as any,
    defaultValues: {
      amount_paid: "",
      payment_method: "bank_transfer",
      payment_reference: "",
    },
  })

  const paymentMethod = watch("payment_method")

  function handleClose() {
    reset()
    recordPayment.reset()
    onOpenChange(false)
  }

  const onSubmit = handleSubmit(async (data) => {
    try {
      await recordPayment.mutateAsync({
        id: paymentId,
        payload: {
          amount_paid: parseFloat(data.amount_paid),
          payment_method: data.payment_method,
          payment_reference: data.payment_reference || null,
        },
      })
      reset()
      onSuccess()
      onOpenChange(false)
    } catch {
      // error shown from mutation state
    }
  })

  return (
    <Dialog open={open} onOpenChange={handleClose}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Record Payment</DialogTitle>
          <DialogDescription>
            {invoiceNumber
              ? `Record a payment for invoice ${invoiceNumber} (${currency}).`
              : "Record a payment against this invoice."}
          </DialogDescription>
        </DialogHeader>

        <form id="record-payment-form" onSubmit={onSubmit} noValidate className="space-y-4">
          {/* Amount paid */}
          <div className="space-y-1.5">
            <Label htmlFor="rp-amount">
              Amount Paid ({currency}){" "}
              <span aria-hidden="true" className="text-destructive">*</span>
            </Label>
            <Input
              id="rp-amount"
              type="number"
              step="0.01"
              min="0.01"
              placeholder="0.00"
              aria-invalid={!!errors.amount_paid}
              {...register("amount_paid")}
            />
            {errors.amount_paid && (
              <p role="alert" className="text-xs text-destructive">
                {errors.amount_paid.message}
              </p>
            )}
          </div>

          {/* Payment method */}
          <div className="space-y-1.5">
            <Label htmlFor="rp-method">
              Payment Method{" "}
              <span aria-hidden="true" className="text-destructive">*</span>
            </Label>
            <Select
              value={paymentMethod}
              onValueChange={(v) =>
                setValue(
                  "payment_method",
                  v as RecordPaymentFormData["payment_method"],
                )
              }
            >
              <SelectTrigger id="rp-method" aria-label="Select payment method">
                <SelectValue placeholder="Select method" />
              </SelectTrigger>
              <SelectContent>
                {PAYMENT_METHODS.map((m) => (
                  <SelectItem key={m.value} value={m.value}>
                    {m.label}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            {errors.payment_method && (
              <p role="alert" className="text-xs text-destructive">
                {errors.payment_method.message}
              </p>
            )}
          </div>

          {/* Payment reference (optional) */}
          <div className="space-y-1.5">
            <Label htmlFor="rp-reference">Payment Reference (optional)</Label>
            <Input
              id="rp-reference"
              placeholder="e.g. TRF-2024-001"
              {...register("payment_reference")}
            />
            {errors.payment_reference && (
              <p role="alert" className="text-xs text-destructive">
                {errors.payment_reference.message}
              </p>
            )}
          </div>

          {/* API error */}
          {recordPayment.isError && (
            <Alert variant="destructive" role="alert">
              <AlertDescription>
                Failed to record payment. Please try again.
              </AlertDescription>
            </Alert>
          )}
        </form>

        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            onClick={handleClose}
            disabled={recordPayment.isPending}
          >
            Cancel
          </Button>
          <Button
            type="submit"
            form="record-payment-form"
            disabled={recordPayment.isPending}
          >
            {recordPayment.isPending ? "Recording…" : "Record Payment"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
