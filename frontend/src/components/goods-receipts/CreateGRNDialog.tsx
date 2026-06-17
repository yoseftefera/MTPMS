"use client"

/**
 * CreateGRNDialog — Dialog for creating a new Goods Receipt Note.
 *
 * Workflow:
 *   1. Enter PO number → search and look up the PO
 *   2. Select warehouse_id
 *   3. Enter delivery_note_number
 *   4. Line items from the matched PO (outstanding qty) with received_quantity input
 *
 * Validates: Requirements 12.1, 22.6
 */

import { useState } from "react"
import { Search, Loader2 } from "lucide-react"
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
import { Alert, AlertDescription } from "@/components/ui/alert"
import {
  Table,
  TableHeader,
  TableBody,
  TableRow,
  TableHead,
  TableCell,
} from "@/components/ui/table"
import { useLookupPO, useCreateGoodsReceipt } from "@/hooks/useGoodsReceipts"
import type { POItemLookup, POLookupResult } from "@/types/goodsReceipt"

// ─── Mock warehouses — replace with useWarehouses() when available ─────────────

const MOCK_WAREHOUSES = [
  { id: "wh-placeholder-1", name: "Main Warehouse"   },
  { id: "wh-placeholder-2", name: "North Warehouse"  },
  { id: "wh-placeholder-3", name: "South Warehouse"  },
]

interface CreateGRNDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  onSuccess?: () => void
}

export function CreateGRNDialog({
  open,
  onOpenChange,
  onSuccess,
}: CreateGRNDialogProps) {
  const [poSearch, setPoSearch] = useState("")
  const [debouncedSearch, setDebouncedSearch] = useState("")
  const [selectedPO, setSelectedPO] = useState<POLookupResult | null>(null)
  const [warehouseId, setWarehouseId] = useState("")
  const [deliveryNote, setDeliveryNote] = useState("")
  const [quantities, setQuantities] = useState<Record<string, string>>({})
  const [formError, setFormError] = useState<string | null>(null)

  const { data: lookupData, isFetching: isLookingUp } = useLookupPO(debouncedSearch)
  const createGRN = useCreateGoodsReceipt()

  const matchedPOs = lookupData?.data ?? []

  function handlePoSearchChange(e: React.ChangeEvent<HTMLInputElement>) {
    const val = e.target.value
    setPoSearch(val)
    setSelectedPO(null)
    // Debounce: update the query input after a short delay
    const timer = setTimeout(() => setDebouncedSearch(val), 400)
    return () => clearTimeout(timer)
  }

  function handleSelectPO(po: POLookupResult) {
    setSelectedPO(po)
    setPoSearch(po.po_number)
    // Initialise quantities to 0
    const init: Record<string, string> = {}
    for (const item of po.items) {
      init[item.id] = ""
    }
    setQuantities(init)
  }

  function handleQtyChange(itemId: string, value: string) {
    setQuantities((prev) => ({ ...prev, [itemId]: value }))
  }

  function handleClose() {
    setPoSearch("")
    setDebouncedSearch("")
    setSelectedPO(null)
    setWarehouseId("")
    setDeliveryNote("")
    setQuantities({})
    setFormError(null)
    createGRN.reset()
    onOpenChange(false)
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault()
    setFormError(null)

    if (!selectedPO) {
      setFormError("Please look up and select a Purchase Order.")
      return
    }
    if (!warehouseId) {
      setFormError("Please select a warehouse.")
      return
    }
    if (!deliveryNote.trim()) {
      setFormError("Delivery note number is required.")
      return
    }

    const items = (selectedPO.items as POItemLookup[])
      .filter((item) => {
        const qty = parseFloat(quantities[item.id] ?? "0")
        return !isNaN(qty) && qty > 0
      })
      .map((item) => ({
        purchase_order_item_id: item.id,
        description: item.description,
        received_quantity: parseFloat(quantities[item.id]),
      }))

    if (items.length === 0) {
      setFormError("Enter a received quantity greater than 0 for at least one line item.")
      return
    }

    // Validate quantities don't exceed outstanding
    for (const item of selectedPO.items as POItemLookup[]) {
      const entered = parseFloat(quantities[item.id] ?? "0")
      if (!isNaN(entered) && entered > item.outstanding_quantity) {
        setFormError(
          `Quantity for "${item.description}" (${entered}) exceeds outstanding quantity (${item.outstanding_quantity}).`,
        )
        return
      }
    }

    try {
      await createGRN.mutateAsync({
        purchase_order_id: selectedPO.id,
        warehouse_id: warehouseId,
        delivery_note_number: deliveryNote.trim(),
        items,
      })
      onSuccess?.()
      handleClose()
    } catch {
      // error shown via createGRN.error
    }
  }

  const apiError =
    createGRN.error instanceof Error
      ? createGRN.error.message
      : createGRN.isError
        ? "Failed to create goods receipt. Please try again."
        : null

  return (
    <Dialog open={open} onOpenChange={handleClose}>
      <DialogContent className="sm:max-w-2xl max-h-[90vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>Create Goods Receipt</DialogTitle>
          <DialogDescription>
            Record delivery of goods against a Purchase Order.
          </DialogDescription>
        </DialogHeader>

        <form id="create-grn-form" onSubmit={handleSubmit} noValidate className="space-y-5">
          {(formError || apiError) && (
            <Alert variant="destructive" role="alert">
              <AlertDescription>{formError ?? apiError}</AlertDescription>
            </Alert>
          )}

          {/* PO number search */}
          <div className="space-y-1.5">
            <Label htmlFor="grn-po-search">
              PO Number <span aria-hidden="true" className="text-destructive">*</span>
            </Label>
            <div className="relative">
              <Search
                className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
                aria-hidden="true"
              />
              <Input
                id="grn-po-search"
                placeholder="Search PO number…"
                value={poSearch}
                onChange={handlePoSearchChange}
                className="pl-9"
                autoComplete="off"
              />
              {isLookingUp && (
                <Loader2
                  className="absolute right-3 top-1/2 size-4 -translate-y-1/2 animate-spin text-muted-foreground"
                  aria-hidden="true"
                />
              )}
            </div>

            {/* Dropdown results */}
            {!selectedPO && matchedPOs.length > 0 && debouncedSearch.length >= 3 && (
              <div
                className="rounded-md border border-border bg-popover shadow-md"
                role="listbox"
                aria-label="Matching purchase orders"
              >
                {matchedPOs.map((po) => (
                  <button
                    key={po.id}
                    type="button"
                    role="option"
                    aria-selected="false"
                    className="flex w-full items-center justify-between px-3 py-2 text-left text-sm hover:bg-muted"
                    onClick={() => handleSelectPO(po)}
                  >
                    <span className="font-mono font-medium">{po.po_number}</span>
                    <span className="text-muted-foreground">
                      {po.supplier?.organization_name ?? ""}
                    </span>
                  </button>
                ))}
              </div>
            )}

            {selectedPO && (
              <p className="text-xs text-green-600 dark:text-green-400">
                ✓ PO selected: {selectedPO.po_number} —{" "}
                {selectedPO.supplier?.organization_name ?? ""}
              </p>
            )}
          </div>

          {/* Warehouse */}
          <div className="space-y-1.5">
            <Label htmlFor="grn-warehouse">
              Warehouse <span aria-hidden="true" className="text-destructive">*</span>
            </Label>
            <select
              id="grn-warehouse"
              value={warehouseId}
              onChange={(e) => setWarehouseId(e.target.value)}
              className="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-xs transition-colors focus:outline-none focus:ring-2 focus:ring-ring"
            >
              <option value="" disabled>Select a warehouse…</option>
              {MOCK_WAREHOUSES.map((wh) => (
                <option key={wh.id} value={wh.id}>{wh.name}</option>
              ))}
            </select>
          </div>

          {/* Delivery note number */}
          <div className="space-y-1.5">
            <Label htmlFor="grn-delivery-note">
              Delivery Note Number <span aria-hidden="true" className="text-destructive">*</span>
            </Label>
            <Input
              id="grn-delivery-note"
              placeholder="e.g. DN-2024-00123"
              value={deliveryNote}
              onChange={(e) => setDeliveryNote(e.target.value)}
            />
          </div>

          {/* Line items table */}
          {selectedPO && selectedPO.items.length > 0 && (
            <div className="space-y-2">
              <Label>Line Items</Label>
              <div className="rounded-md border border-border overflow-hidden">
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>Description</TableHead>
                      <TableHead className="text-right">Ordered</TableHead>
                      <TableHead className="text-right">Outstanding</TableHead>
                      <TableHead>UoM</TableHead>
                      <TableHead className="text-right w-32">Received Qty</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {(selectedPO.items as POItemLookup[]).map((item) => (
                      <TableRow key={item.id}>
                        <TableCell className="text-sm">{item.description}</TableCell>
                        <TableCell className="text-right text-sm tabular-nums">
                          {parseFloat(item.quantity).toLocaleString()}
                        </TableCell>
                        <TableCell className="text-right text-sm tabular-nums">
                          {item.outstanding_quantity.toLocaleString()}
                        </TableCell>
                        <TableCell className="text-sm text-muted-foreground">
                          {item.unit_of_measure}
                        </TableCell>
                        <TableCell className="text-right">
                          <Input
                            type="number"
                            min={0}
                            max={item.outstanding_quantity}
                            step="any"
                            placeholder="0"
                            value={quantities[item.id] ?? ""}
                            onChange={(e) => handleQtyChange(item.id, e.target.value)}
                            className="h-8 w-28 text-right tabular-nums"
                            aria-label={`Received quantity for ${item.description}`}
                          />
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </div>
            </div>
          )}

          {selectedPO && selectedPO.items.length === 0 && (
            <p className="text-sm text-muted-foreground">
              No outstanding line items for this PO.
            </p>
          )}
        </form>

        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            onClick={handleClose}
            disabled={createGRN.isPending}
          >
            Cancel
          </Button>
          <Button
            type="submit"
            form="create-grn-form"
            disabled={createGRN.isPending}
          >
            {createGRN.isPending ? (
              <>
                <Loader2 className="size-4 animate-spin" aria-hidden="true" />
                Creating…
              </>
            ) : (
              "Create GRN"
            )}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
