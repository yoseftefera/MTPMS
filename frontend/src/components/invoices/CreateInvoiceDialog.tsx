"use client"

/**
 * CreateInvoiceDialog — modal form for suppliers to submit an invoice.
 *
 * Fields: purchase_order_id (optional), contract_id (optional),
 *         invoice_date, due_date, currency, total_amount, notes
 *
 * Validates: Requirements 14.1, 22.6
 */

import { useForm } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Textarea } from "@/components/ui/textarea"
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
  createInvoiceSchema,
  CURRENCIES,
  type CreateInvoiceFormData,
} from "@/lib/validations/invoices"
import { useCreateInvoice } from "@/hooks/useInvoices"

// ─── Props ────────────────────────────────────────────────────────────────────

interface CreateInvoiceDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  onSuccess: () => void
}

// ─── Component ────────────────────────────────────────────────────────────────

export function CreateInvoiceDialog({
  open,
  onOpenChange,
  onSuccess,
}: CreateInvoiceDialogProps) {
  const createInvoice = useCreateInvoice()

  const {
    register,
    handleSubmit,
    reset,
    setValue,
    watch,
    formState: { errors },
  } = useForm<CreateInvoiceFormData>({
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    resolver: zodResolver(createInvoiceSchema) as any,
    defaultValues: {
      purchase_order_id: "",
      contract_id: "",
      invoice_date: "",
      due_date: "",
      currency: "USD",
      total_amount: "",
      notes: "",
    },
  })

  const currency = watch("currency")

  function handleClose() {
    reset()
    createInvoice.reset()
    onOpenChange(false)
  }

  const onSubmit = handleSubmit(async (data) => {
    try {
      await createInvoice.mutateAsync({
        purchase_order_id: data.purchase_order_id || null,
        contract_id: data.contract_id || null,
        invoice_date: data.invoice_date,
        due_date: data.due_date,
        currency: data.currency,
        total_amount: parseFloat(data.total_amount),
        notes: data.notes || null,
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
      <DialogContent className="sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>Submit Invoice</DialogTitle>
          <DialogDescription>
            Submit an invoice against a purchase order or contract.
          </DialogDescription>
        </DialogHeader>

        <form id="create-invoice-form" onSubmit={onSubmit} noValidate className="space-y-4">
          {/* PO reference (optional) */}
          <div className="space-y-1.5">
            <Label htmlFor="invoice-po-id">Purchase Order ID (optional)</Label>
            <Input
              id="invoice-po-id"
              placeholder="e.g. po-uuid-here"
              {...register("purchase_order_id")}
            />
            {errors.purchase_order_id && (
              <p role="alert" className="text-xs text-destructive">
                {errors.purchase_order_id.message}
              </p>
            )}
          </div>

          {/* Contract reference (optional) */}
          <div className="space-y-1.5">
            <Label htmlFor="invoice-contract-id">Contract ID (optional)</Label>
            <Input
              id="invoice-contract-id"
              placeholder="e.g. contract-uuid-here"
              {...register("contract_id")}
            />
            {errors.contract_id && (
              <p role="alert" className="text-xs text-destructive">
                {errors.contract_id.message}
              </p>
            )}
          </div>

          {/* Invoice date + Due date */}
          <div className="grid grid-cols-2 gap-3">
            <div className="space-y-1.5">
              <Label htmlFor="invoice-date">
                Invoice Date <span aria-hidden="true" className="text-destructive">*</span>
              </Label>
              <Input
                id="invoice-date"
                type="date"
                aria-invalid={!!errors.invoice_date}
                {...register("invoice_date")}
              />
              {errors.invoice_date && (
                <p role="alert" className="text-xs text-destructive">
                  {errors.invoice_date.message}
                </p>
              )}
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="invoice-due-date">
                Due Date <span aria-hidden="true" className="text-destructive">*</span>
              </Label>
              <Input
                id="invoice-due-date"
                type="date"
                aria-invalid={!!errors.due_date}
                {...register("due_date")}
              />
              {errors.due_date && (
                <p role="alert" className="text-xs text-destructive">
                  {errors.due_date.message}
                </p>
              )}
            </div>
          </div>

          {/* Currency + Amount */}
          <div className="grid grid-cols-2 gap-3">
            <div className="space-y-1.5">
              <Label htmlFor="invoice-currency">
                Currency <span aria-hidden="true" className="text-destructive">*</span>
              </Label>
              <Select
                value={currency}
                onValueChange={(v) => setValue("currency", v)}
              >
                <SelectTrigger id="invoice-currency" aria-label="Select currency">
                  <SelectValue placeholder="USD" />
                </SelectTrigger>
                <SelectContent>
                  {CURRENCIES.map((c) => (
                    <SelectItem key={c} value={c}>
                      {c}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              {errors.currency && (
                <p role="alert" className="text-xs text-destructive">
                  {errors.currency.message}
                </p>
              )}
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="invoice-amount">
                Total Amount <span aria-hidden="true" className="text-destructive">*</span>
              </Label>
              <Input
                id="invoice-amount"
                type="number"
                step="0.01"
                min="0.01"
                placeholder="0.00"
                aria-invalid={!!errors.total_amount}
                {...register("total_amount")}
              />
              {errors.total_amount && (
                <p role="alert" className="text-xs text-destructive">
                  {errors.total_amount.message}
                </p>
              )}
            </div>
          </div>

          {/* Notes */}
          <div className="space-y-1.5">
            <Label htmlFor="invoice-notes">Notes (optional)</Label>
            <Textarea
              id="invoice-notes"
              placeholder="Any additional information…"
              rows={3}
              {...register("notes")}
            />
            {errors.notes && (
              <p role="alert" className="text-xs text-destructive">
                {errors.notes.message}
              </p>
            )}
          </div>

          {/* API error */}
          {createInvoice.isError && (
            <Alert variant="destructive" role="alert">
              <AlertDescription>
                Failed to submit invoice. Please check the details and try again.
              </AlertDescription>
            </Alert>
          )}
        </form>

        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            onClick={handleClose}
            disabled={createInvoice.isPending}
          >
            Cancel
          </Button>
          <Button
            type="submit"
            form="create-invoice-form"
            disabled={createInvoice.isPending}
          >
            {createInvoice.isPending ? "Submitting…" : "Submit Invoice"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
