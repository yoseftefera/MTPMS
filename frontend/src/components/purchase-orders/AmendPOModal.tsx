"use client"

/**
 * AmendPOModal — Dialog for amending an existing Purchase Order.
 *
 * Editable fields: delivery address, required delivery date, notes, line items.
 * Shows a warning banner when the PO status is 'accepted'
 * (post-acceptance changes require supplier acknowledgment per Req 10.9).
 *
 * Calls PUT /purchase-orders/{id} on submit and invalidates the detail query.
 *
 * Validates: Requirements 10.9, 22.6, 22.7
 */

import { useEffect } from "react"
import { useForm, useFieldArray } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"
import { Plus, Trash2, AlertTriangle } from "lucide-react"
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
import { Separator } from "@/components/ui/separator"
import {
  amendPOSchema,
  type AmendPOFormData,
} from "@/lib/validations/purchaseOrders"
import { useAmendPO } from "@/hooks/usePurchaseOrders"
import { formatCurrency } from "@/lib/utils"
import type { PurchaseOrderDetail } from "@/types/purchaseOrder"

// ─── Props ────────────────────────────────────────────────────────────────────

interface AmendPOModalProps {
  po: PurchaseOrderDetail
  open: boolean
  onOpenChange: (open: boolean) => void
  onSuccess?: () => void
}

// ─── Helpers ──────────────────────────────────────────────────────────────────

function calcLineTotal(qty: string, price: string): number {
  const q = parseFloat(qty)
  const p = parseFloat(price)
  return isNaN(q) || isNaN(p) ? 0 : q * p
}

// ─── Component ────────────────────────────────────────────────────────────────

export function AmendPOModal({
  po,
  open,
  onOpenChange,
  onSuccess,
}: AmendPOModalProps) {
  const amendPO = useAmendPO()
  const isPostAcceptance = po.status === "accepted"

  const {
    register,
    handleSubmit,
    control,
    watch,
    reset,
    formState: { errors },
  } = useForm<AmendPOFormData>({
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    resolver: zodResolver(amendPOSchema) as any,
    defaultValues: {
      delivery_address: po.delivery_address ?? "",
      required_delivery_date: po.required_delivery_date
        ? po.required_delivery_date.slice(0, 10)
        : "",
      notes: po.notes ?? "",
      items: (po.items ?? []).map((item) => ({
        description: item.description,
        quantity: item.quantity,
        unit_of_measure: item.unit_of_measure,
        unit_price: item.unit_price,
      })),
    },
  })

  // Re-populate form when PO data changes (e.g. re-open modal)
  useEffect(() => {
    if (open) {
      reset({
        delivery_address: po.delivery_address ?? "",
        required_delivery_date: po.required_delivery_date
          ? po.required_delivery_date.slice(0, 10)
          : "",
        notes: po.notes ?? "",
        items: (po.items ?? []).map((item) => ({
          description: item.description,
          quantity: item.quantity,
          unit_of_measure: item.unit_of_measure,
          unit_price: item.unit_price,
        })),
      })
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, po.id])

  const { fields, append, remove } = useFieldArray({ control, name: "items" })
  const items = watch("items")
  const currency = po.currency ?? "USD"

  const lineTotals = items.map((item) =>
    calcLineTotal(item.quantity ?? "", item.unit_price ?? ""),
  )
  const grandTotal = lineTotals.reduce((sum, t) => sum + t, 0)

  const onSubmit = handleSubmit(async (data) => {
    try {
      await amendPO.mutateAsync({
        id: po.id,
        payload: {
          delivery_address: data.delivery_address || undefined,
          required_delivery_date: data.required_delivery_date || undefined,
          notes: data.notes || undefined,
          items: data.items.map((item) => ({
            description: item.description,
            quantity: item.quantity,
            unit_of_measure: item.unit_of_measure,
            unit_price: item.unit_price,
          })),
        },
      })
      onOpenChange(false)
      onSuccess?.()
    } catch {
      // surfaced via mutation state
    }
  })

  function handleClose() {
    onOpenChange(false)
  }

  const serverErrorMsg = (
    amendPO.error as { response?: { data?: { message?: string } } }
  )?.response?.data?.message

  return (
    <Dialog open={open} onOpenChange={handleClose}>
      <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-3xl">
        <DialogHeader>
          <DialogTitle>Amend Purchase Order</DialogTitle>
          <DialogDescription>
            Update the delivery details, notes, or line items for{" "}
            <strong className="font-mono">{po.po_number}</strong>.
          </DialogDescription>
        </DialogHeader>

        {/* Post-acceptance warning */}
        {isPostAcceptance && (
          <Alert className="border-amber-200 bg-amber-50 dark:bg-amber-950/30" role="note">
            <AlertTriangle
              className="size-4 text-amber-600 dark:text-amber-400"
              aria-hidden="true"
            />
            <AlertDescription className="text-amber-700 dark:text-amber-300">
              Post-acceptance changes require supplier acknowledgment. The
              supplier will be notified and the PO will be flagged as pending
              acknowledgment.
            </AlertDescription>
          </Alert>
        )}

        {amendPO.isError && (
          <Alert variant="destructive" role="alert">
            <AlertDescription>
              {serverErrorMsg ?? "Failed to amend purchase order. Please try again."}
            </AlertDescription>
          </Alert>
        )}

        <form id="amend-po-form" onSubmit={onSubmit} noValidate className="space-y-6">
          {/* ── Delivery address ─────────────────────────────────────────── */}
          <div className="space-y-1.5">
            <Label htmlFor="amend-delivery-address">Delivery Address</Label>
            <Textarea
              id="amend-delivery-address"
              rows={2}
              aria-invalid={!!errors.delivery_address}
              {...register("delivery_address")}
            />
            {errors.delivery_address && (
              <p role="alert" className="text-xs text-destructive">
                {errors.delivery_address.message}
              </p>
            )}
          </div>

          {/* ── Required delivery date ───────────────────────────────────── */}
          <div className="space-y-1.5">
            <Label htmlFor="amend-delivery-date">Required Delivery Date</Label>
            <Input
              id="amend-delivery-date"
              type="date"
              aria-invalid={!!errors.required_delivery_date}
              {...register("required_delivery_date")}
            />
            {errors.required_delivery_date && (
              <p role="alert" className="text-xs text-destructive">
                {errors.required_delivery_date.message}
              </p>
            )}
          </div>

          {/* ── Notes ────────────────────────────────────────────────────── */}
          <div className="space-y-1.5">
            <Label htmlFor="amend-notes">Notes</Label>
            <Textarea
              id="amend-notes"
              rows={2}
              placeholder="Internal remarks or additional instructions…"
              {...register("notes")}
            />
          </div>

          <Separator />

          {/* ── Line items ───────────────────────────────────────────────── */}
          <section aria-labelledby="amend-items-heading">
            <div className="mb-3 flex items-center justify-between">
              <h3
                id="amend-items-heading"
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
                    unit_price: "",
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
                      <span className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
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
                        <Label htmlFor={`amend-item-desc-${index}`}>
                          Description{" "}
                          <span aria-hidden="true" className="text-destructive">*</span>
                        </Label>
                        <Input
                          id={`amend-item-desc-${index}`}
                          aria-invalid={!!itemErrors?.description}
                          {...register(`items.${index}.description`)}
                        />
                        {itemErrors?.description && (
                          <p role="alert" className="text-xs text-destructive">
                            {itemErrors.description.message}
                          </p>
                        )}
                      </div>

                      {/* Qty */}
                      <div className="space-y-1">
                        <Label htmlFor={`amend-item-qty-${index}`}>
                          Quantity{" "}
                          <span aria-hidden="true" className="text-destructive">*</span>
                        </Label>
                        <Input
                          id={`amend-item-qty-${index}`}
                          type="number"
                          step="0.001"
                          min="0.001"
                          aria-invalid={!!itemErrors?.quantity}
                          {...register(`items.${index}.quantity`)}
                        />
                        {itemErrors?.quantity && (
                          <p role="alert" className="text-xs text-destructive">
                            {itemErrors.quantity.message}
                          </p>
                        )}
                      </div>

                      {/* UoM */}
                      <div className="space-y-1">
                        <Label htmlFor={`amend-item-uom-${index}`}>
                          Unit of Measure{" "}
                          <span aria-hidden="true" className="text-destructive">*</span>
                        </Label>
                        <Input
                          id={`amend-item-uom-${index}`}
                          aria-invalid={!!itemErrors?.unit_of_measure}
                          {...register(`items.${index}.unit_of_measure`)}
                        />
                        {itemErrors?.unit_of_measure && (
                          <p role="alert" className="text-xs text-destructive">
                            {itemErrors.unit_of_measure.message}
                          </p>
                        )}
                      </div>

                      {/* Unit Price */}
                      <div className="space-y-1">
                        <Label htmlFor={`amend-item-price-${index}`}>
                          Unit Price{" "}
                          <span aria-hidden="true" className="text-destructive">*</span>
                        </Label>
                        <Input
                          id={`amend-item-price-${index}`}
                          type="number"
                          step="0.01"
                          min="0"
                          aria-invalid={!!itemErrors?.unit_price}
                          {...register(`items.${index}.unit_price`)}
                        />
                        {itemErrors?.unit_price && (
                          <p role="alert" className="text-xs text-destructive">
                            {itemErrors.unit_price.message}
                          </p>
                        )}
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
                Total Amount:{" "}
                <span className="tabular-nums text-base">
                  {formatCurrency(grandTotal.toFixed(2), currency)}
                </span>
              </span>
            </div>
          </section>
        </form>

        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            onClick={handleClose}
            disabled={amendPO.isPending}
          >
            Cancel
          </Button>
          <Button
            type="submit"
            form="amend-po-form"
            disabled={amendPO.isPending}
          >
            {amendPO.isPending ? "Saving…" : "Save Changes"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
