"use client"

/**
 * CreatePOForm — Dialog for creating a new draft Purchase Order.
 *
 * Features:
 * - Supplier select (active suppliers only)
 * - Department select
 * - Delivery address textarea
 * - Required delivery date picker
 * - Currency select
 * - Dynamic line items (description, qty, UoM, unit price) with real-time totals
 * - Optional notes
 * - React Hook Form + Zod validation
 * - Calls POST /purchase-orders on submit
 *
 * Validates: Requirements 10.2, 10.9, 22.6, 22.7
 */

import { useForm, useFieldArray } from "react-hook-form"
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
  createPOSchema,
  CURRENCIES,
  type CreatePOFormData,
} from "@/lib/validations/purchaseOrders"
import { useCreatePO } from "@/hooks/usePurchaseOrders"
import { formatCurrency } from "@/lib/utils"
import type { Supplier } from "@/types/models.types"

// ─── Types ────────────────────────────────────────────────────────────────────

interface Department {
  id: string
  name: string
}

interface CreatePOFormProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  suppliers: Supplier[]
  departments: Department[]
  onSuccess?: () => void
}

// ─── Helpers ──────────────────────────────────────────────────────────────────

function calcLineTotal(qty: string, price: string): number {
  const q = parseFloat(qty)
  const p = parseFloat(price)
  return isNaN(q) || isNaN(p) ? 0 : q * p
}

// ─── Component ────────────────────────────────────────────────────────────────

export function CreatePOForm({
  open,
  onOpenChange,
  suppliers,
  departments,
  onSuccess,
}: CreatePOFormProps) {
  const createPO = useCreatePO()

  const {
    register,
    handleSubmit,
    control,
    setValue,
    watch,
    reset,
    formState: { errors },
  } = useForm<CreatePOFormData>({
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    resolver: zodResolver(createPOSchema) as any,
    defaultValues: {
      supplier_id: "",
      department_id: "",
      delivery_address: "",
      required_delivery_date: "",
      currency: "USD",
      notes: "",
      items: [
        {
          description: "",
          quantity: "",
          unit_of_measure: "",
          unit_price: "",
        },
      ],
    },
  })

  const { fields, append, remove } = useFieldArray({ control, name: "items" })

  const currency = watch("currency")
  const items = watch("items")

  const lineTotals = items.map((item) =>
    calcLineTotal(item.quantity, item.unit_price),
  )
  const grandTotal = lineTotals.reduce((sum, t) => sum + t, 0)

  const onSubmit = handleSubmit(async (data) => {
    try {
      await createPO.mutateAsync({
        supplier_id: data.supplier_id,
        department_id: data.department_id,
        delivery_address: data.delivery_address,
        required_delivery_date: data.required_delivery_date,
        currency: data.currency,
        notes: data.notes || undefined,
        items: data.items.map((item) => ({
          description: item.description,
          quantity: item.quantity,
          unit_of_measure: item.unit_of_measure,
          unit_price: item.unit_price,
        })),
      })
      handleClose()
      onSuccess?.()
    } catch {
      // surfaced via mutation state
    }
  })

  function handleClose() {
    reset()
    onOpenChange(false)
  }

  const serverErrorMsg = (
    createPO.error as { response?: { data?: { message?: string } } }
  )?.response?.data?.message

  return (
    <Dialog open={open} onOpenChange={handleClose}>
      <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-3xl">
        <DialogHeader>
          <DialogTitle>Create Purchase Order</DialogTitle>
          <DialogDescription>
            Fill in the details for the new purchase order. At least one line
            item is required.
          </DialogDescription>
        </DialogHeader>

        {createPO.isError && (
          <Alert variant="destructive" role="alert">
            <AlertDescription>
              {serverErrorMsg ?? "Failed to create purchase order. Please try again."}
            </AlertDescription>
          </Alert>
        )}

        <form id="create-po-form" onSubmit={onSubmit} noValidate className="space-y-6">
          {/* ── Supplier + Department ───────────────────────────────────── */}
          <div className="grid gap-4 sm:grid-cols-2">
            {/* Supplier */}
            <div className="space-y-1.5">
              <Label htmlFor="po-supplier">
                Supplier <span aria-hidden="true" className="text-destructive">*</span>
              </Label>
              <Select
                value={watch("supplier_id")}
                onValueChange={(val) =>
                  setValue("supplier_id", val, { shouldValidate: true })
                }
              >
                <SelectTrigger
                  id="po-supplier"
                  aria-label="Select supplier"
                  aria-invalid={!!errors.supplier_id}
                  aria-describedby={errors.supplier_id ? "po-supplier-error" : undefined}
                >
                  <SelectValue placeholder="Select supplier…" />
                </SelectTrigger>
                <SelectContent>
                  {suppliers.map((s) => (
                    <SelectItem key={s.id} value={s.id}>
                      {s.organization_name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              {errors.supplier_id && (
                <p id="po-supplier-error" role="alert" className="text-xs text-destructive">
                  {errors.supplier_id.message}
                </p>
              )}
            </div>

            {/* Department */}
            <div className="space-y-1.5">
              <Label htmlFor="po-department">
                Department <span aria-hidden="true" className="text-destructive">*</span>
              </Label>
              <Select
                value={watch("department_id")}
                onValueChange={(val) =>
                  setValue("department_id", val, { shouldValidate: true })
                }
              >
                <SelectTrigger
                  id="po-department"
                  aria-label="Select department"
                  aria-invalid={!!errors.department_id}
                  aria-describedby={errors.department_id ? "po-dept-error" : undefined}
                >
                  <SelectValue placeholder="Select department…" />
                </SelectTrigger>
                <SelectContent>
                  {departments.map((d) => (
                    <SelectItem key={d.id} value={d.id}>
                      {d.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              {errors.department_id && (
                <p id="po-dept-error" role="alert" className="text-xs text-destructive">
                  {errors.department_id.message}
                </p>
              )}
            </div>
          </div>

          {/* ── Delivery address ─────────────────────────────────────────── */}
          <div className="space-y-1.5">
            <Label htmlFor="po-delivery-address">
              Delivery Address <span aria-hidden="true" className="text-destructive">*</span>
            </Label>
            <Textarea
              id="po-delivery-address"
              placeholder="Full delivery address…"
              rows={2}
              aria-invalid={!!errors.delivery_address}
              aria-describedby={errors.delivery_address ? "po-addr-error" : undefined}
              {...register("delivery_address")}
            />
            {errors.delivery_address && (
              <p id="po-addr-error" role="alert" className="text-xs text-destructive">
                {errors.delivery_address.message}
              </p>
            )}
          </div>

          {/* ── Delivery date + Currency ──────────────────────────────────── */}
          <div className="grid gap-4 sm:grid-cols-2">
            <div className="space-y-1.5">
              <Label htmlFor="po-delivery-date">
                Required Delivery Date{" "}
                <span aria-hidden="true" className="text-destructive">*</span>
              </Label>
              <Input
                id="po-delivery-date"
                type="date"
                aria-invalid={!!errors.required_delivery_date}
                aria-describedby={
                  errors.required_delivery_date ? "po-date-error" : undefined
                }
                {...register("required_delivery_date")}
              />
              {errors.required_delivery_date && (
                <p id="po-date-error" role="alert" className="text-xs text-destructive">
                  {errors.required_delivery_date.message}
                </p>
              )}
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="po-currency">Currency</Label>
              <Select
                value={currency}
                onValueChange={(val) =>
                  setValue("currency", val, { shouldValidate: true })
                }
              >
                <SelectTrigger id="po-currency" aria-label="Select currency">
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

          {/* ── Notes ────────────────────────────────────────────────────── */}
          <div className="space-y-1.5">
            <Label htmlFor="po-notes">
              Notes{" "}
              <span className="text-xs text-muted-foreground">(optional)</span>
            </Label>
            <Textarea
              id="po-notes"
              placeholder="Internal remarks or additional instructions…"
              rows={2}
              {...register("notes")}
            />
          </div>

          <Separator />

          {/* ── Line items ───────────────────────────────────────────────── */}
          <section aria-labelledby="po-items-heading">
            <div className="mb-3 flex items-center justify-between">
              <h3
                id="po-items-heading"
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
                        <Label htmlFor={`po-item-desc-${index}`}>
                          Description{" "}
                          <span aria-hidden="true" className="text-destructive">*</span>
                        </Label>
                        <Input
                          id={`po-item-desc-${index}`}
                          placeholder="Item description"
                          aria-invalid={!!itemErrors?.description}
                          aria-describedby={
                            itemErrors?.description
                              ? `po-item-desc-error-${index}`
                              : undefined
                          }
                          {...register(`items.${index}.description`)}
                        />
                        {itemErrors?.description && (
                          <p
                            id={`po-item-desc-error-${index}`}
                            role="alert"
                            className="text-xs text-destructive"
                          >
                            {itemErrors.description.message}
                          </p>
                        )}
                      </div>

                      {/* Quantity */}
                      <div className="space-y-1">
                        <Label htmlFor={`po-item-qty-${index}`}>
                          Quantity{" "}
                          <span aria-hidden="true" className="text-destructive">*</span>
                        </Label>
                        <Input
                          id={`po-item-qty-${index}`}
                          type="number"
                          step="0.001"
                          min="0.001"
                          placeholder="0"
                          aria-invalid={!!itemErrors?.quantity}
                          {...register(`items.${index}.quantity`)}
                        />
                        {itemErrors?.quantity && (
                          <p role="alert" className="text-xs text-destructive">
                            {itemErrors.quantity.message}
                          </p>
                        )}
                      </div>

                      {/* Unit of Measure */}
                      <div className="space-y-1">
                        <Label htmlFor={`po-item-uom-${index}`}>
                          Unit of Measure{" "}
                          <span aria-hidden="true" className="text-destructive">*</span>
                        </Label>
                        <Input
                          id={`po-item-uom-${index}`}
                          placeholder="e.g. pcs, kg, box"
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
                        <Label htmlFor={`po-item-price-${index}`}>
                          Unit Price{" "}
                          <span aria-hidden="true" className="text-destructive">*</span>
                        </Label>
                        <Input
                          id={`po-item-price-${index}`}
                          type="number"
                          step="0.01"
                          min="0"
                          placeholder="0.00"
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
            disabled={createPO.isPending}
          >
            Cancel
          </Button>
          <Button
            type="submit"
            form="create-po-form"
            disabled={createPO.isPending}
          >
            {createPO.isPending ? "Creating…" : "Create Purchase Order"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
