"use client"

/**
 * Transfer Budget dialog form.
 *
 * Lets Finance_Officer or Tenant_Admin transfer a portion of one budget's
 * available balance to another budget.
 *
 * Features:
 * - Shows available balance of the selected "from" budget in real-time
 * - Validates transfer amount does not exceed available balance
 * - Optional transfer note
 *
 * Validates: Requirements 13.1, 13.10, 22.5, 22.7
 */

import { useMemo } from "react"
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
import { Alert, AlertDescription } from "@/components/ui/alert"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { transferBudgetSchema, type TransferBudgetFormData } from "@/lib/validations/budgets"
import { useTransferBudget } from "@/hooks/useBudget"
import { formatCurrency } from "@/lib/utils"
import type { Budget } from "@/types/budget"

interface TransferBudgetFormProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  budgets: Budget[]
  onSuccess?: () => void
}

export function TransferBudgetForm({
  open,
  onOpenChange,
  budgets,
  onSuccess,
}: TransferBudgetFormProps) {
  const transferBudget = useTransferBudget()

  const form = useForm<TransferBudgetFormData>({
    resolver: zodResolver(transferBudgetSchema),
    defaultValues: {
      from_budget_id: "",
      to_budget_id: "",
      amount: "",
      note: "",
    },
  })

  const {
    register,
    handleSubmit,
    setValue,
    watch,
    reset,
    formState: { errors },
  } = form

  const fromBudgetId = watch("from_budget_id")
  const toBudgetId = watch("to_budget_id")

  // Resolve the selected "from" budget to show its available balance
  const fromBudget = useMemo(
    () => budgets.find((b) => b.id === fromBudgetId) ?? null,
    [budgets, fromBudgetId],
  )

  // Destination budgets exclude the source budget
  const destinationBudgets = useMemo(
    () => budgets.filter((b) => b.id !== fromBudgetId),
    [budgets, fromBudgetId],
  )

  const onSubmit = handleSubmit(async (data) => {
    const available = fromBudget ? parseFloat(fromBudget.available_amount) : Infinity

    if (parseFloat(data.amount) > available) {
      form.setError("amount", {
        message: `Amount cannot exceed available balance of ${formatCurrency(fromBudget?.available_amount ?? "0", fromBudget?.currency)}`,
      })
      return
    }

    await transferBudget.mutateAsync(
      {
        from_budget_id: data.from_budget_id,
        to_budget_id: data.to_budget_id,
        amount: parseFloat(data.amount).toFixed(2),
        note: data.note || undefined,
      },
      {
        onSuccess: () => {
          reset()
          onOpenChange(false)
          onSuccess?.()
        },
      },
    )
  })

  const serverError = transferBudget.error as
    | { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }
    | null

  const handleClose = () => {
    reset()
    onOpenChange(false)
  }

  function budgetLabel(b: Budget) {
    return `${b.department_name} — FY${b.fiscal_year}`
  }

  return (
    <Dialog open={open} onOpenChange={handleClose}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Transfer Budget</DialogTitle>
          <DialogDescription>
            Move available funds from one department budget to another within the
            same fiscal year.
          </DialogDescription>
        </DialogHeader>

        {serverError?.response?.data?.message && (
          <Alert variant="destructive" role="alert">
            <AlertDescription>
              {serverError.response.data.message}
              {serverError.response.data.errors && (
                <ul className="mt-1 list-disc pl-4 text-xs">
                  {Object.entries(serverError.response.data.errors).map(([field, msgs]) =>
                    msgs.map((msg, i) => <li key={`${field}-${i}`}>{msg}</li>),
                  )}
                </ul>
              )}
            </AlertDescription>
          </Alert>
        )}

        <form id="transfer-budget-form" onSubmit={onSubmit} noValidate className="space-y-4">
          {/* From budget */}
          <div className="space-y-1.5">
            <Label htmlFor="transfer-from">
              From Budget <span aria-hidden="true" className="text-destructive">*</span>
            </Label>
            <div className="relative">
              <Select
                value={fromBudgetId}
                onValueChange={(val) => {
                  setValue("from_budget_id", val, { shouldValidate: true })
                  // Clear destination if it was the same budget
                  if (toBudgetId === val) {
                    setValue("to_budget_id", "", { shouldValidate: false })
                  }
                }}
              >
                <SelectTrigger
                  id="transfer-from"
                  aria-label="Select source budget"
                  aria-invalid={!!errors.from_budget_id}
                  aria-describedby={errors.from_budget_id ? "from-error" : "from-hint"}
                >
                  <SelectValue placeholder="Select source budget…" />
                </SelectTrigger>
                <SelectContent>
                  {budgets.map((b) => (
                    <SelectItem key={b.id} value={b.id}>
                      {budgetLabel(b)}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            {fromBudget && (
              <p
                id="from-hint"
                className="text-xs text-muted-foreground"
                aria-live="polite"
              >
                Available balance:{" "}
                <span className="font-medium text-foreground">
                  {formatCurrency(fromBudget.available_amount, fromBudget.currency)}
                </span>
              </p>
            )}
            {errors.from_budget_id && (
              <p id="from-error" role="alert" className="text-xs text-destructive">
                {errors.from_budget_id.message}
              </p>
            )}
          </div>

          {/* To budget */}
          <div className="space-y-1.5">
            <Label htmlFor="transfer-to">
              To Budget <span aria-hidden="true" className="text-destructive">*</span>
            </Label>
            <div className="relative">
              <Select
                value={toBudgetId}
                onValueChange={(val) =>
                  setValue("to_budget_id", val, { shouldValidate: true })
                }
              >
                <SelectTrigger
                  id="transfer-to"
                  aria-label="Select destination budget"
                  aria-invalid={!!errors.to_budget_id}
                  aria-describedby={errors.to_budget_id ? "to-error" : undefined}
                >
                  <SelectValue placeholder="Select destination budget…" />
                </SelectTrigger>
                <SelectContent>
                  {destinationBudgets.map((b) => (
                    <SelectItem key={b.id} value={b.id}>
                      {budgetLabel(b)}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            {errors.to_budget_id && (
              <p id="to-error" role="alert" className="text-xs text-destructive">
                {errors.to_budget_id.message}
              </p>
            )}
          </div>

          {/* Amount */}
          <div className="space-y-1.5">
            <Label htmlFor="transfer-amount">
              Amount <span aria-hidden="true" className="text-destructive">*</span>
            </Label>
            <Input
              id="transfer-amount"
              type="number"
              step="0.01"
              min="0.01"
              placeholder="0.00"
              aria-invalid={!!errors.amount}
              aria-describedby={errors.amount ? "amount-error" : undefined}
              {...register("amount")}
            />
            {errors.amount && (
              <p id="amount-error" role="alert" className="text-xs text-destructive">
                {errors.amount.message}
              </p>
            )}
          </div>

          {/* Note (optional) */}
          <div className="space-y-1.5">
            <Label htmlFor="transfer-note">Note (optional)</Label>
            <textarea
              id="transfer-note"
              rows={3}
              placeholder="Reason for transfer…"
              className="flex w-full rounded-lg border border-input bg-background px-3 py-2 text-sm text-foreground shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 disabled:pointer-events-none disabled:opacity-50 resize-none"
              aria-describedby={errors.note ? "note-error" : undefined}
              {...register("note")}
            />
            {errors.note && (
              <p id="note-error" role="alert" className="text-xs text-destructive">
                {errors.note.message}
              </p>
            )}
          </div>
        </form>

        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            onClick={handleClose}
            disabled={transferBudget.isPending}
          >
            Cancel
          </Button>
          <Button
            type="submit"
            form="transfer-budget-form"
            disabled={transferBudget.isPending}
          >
            {transferBudget.isPending ? "Transferring…" : "Transfer Budget"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
