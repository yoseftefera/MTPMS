"use client"

/**
 * Create Budget (Allocate) dialog form.
 *
 * Allows Finance_Officer or Tenant_Admin to allocate an annual budget
 * for a specific department and fiscal year.
 *
 * Uses React Hook Form + Zod.
 * On success: invalidates the budgets query and closes the dialog.
 *
 * Validates: Requirements 13.1, 22.5, 22.7
 */

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
import { createBudgetSchema, type CreateBudgetFormData } from "@/lib/validations/budgets"
import { useCreateBudget } from "@/hooks/useBudget"

interface Department {
  id: string
  name: string
}

interface CreateBudgetFormProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  departments: Department[]
  onSuccess?: () => void
}

const CURRENCIES = ["USD", "EUR", "GBP", "ETB", "KES", "NGN", "GHS", "ZAR"]

const currentYear = new Date().getFullYear()
const FISCAL_YEARS = Array.from({ length: 6 }, (_, i) => currentYear - 1 + i)

export function CreateBudgetForm({
  open,
  onOpenChange,
  departments,
  onSuccess,
}: CreateBudgetFormProps) {
  const createBudget = useCreateBudget()

  const form = useForm<CreateBudgetFormData>({
    resolver: zodResolver(createBudgetSchema),
    defaultValues: {
      department_id: "",
      fiscal_year: currentYear,
      total_amount: "",
      currency: "USD",
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

  const currency = watch("currency")
  const fiscalYear = watch("fiscal_year")

  const onSubmit = handleSubmit(async (data) => {
    await createBudget.mutateAsync(
      {
        department_id: data.department_id,
        fiscal_year: data.fiscal_year,
        total_amount: parseFloat(data.total_amount).toFixed(2),
        currency: data.currency,
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

  const serverError = createBudget.error as
    | { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }
    | null

  const handleClose = () => {
    reset()
    onOpenChange(false)
  }

  return (
    <Dialog open={open} onOpenChange={handleClose}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Allocate Budget</DialogTitle>
          <DialogDescription>
            Set the annual budget allocation for a department. Amounts are in the
            selected currency.
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

        <form id="create-budget-form" onSubmit={onSubmit} noValidate className="space-y-4">
          {/* Department */}
          <div className="space-y-1.5">
            <Label htmlFor="budget-department">
              Department <span aria-hidden="true" className="text-destructive">*</span>
            </Label>
            <div className="relative">
              <Select
                value={watch("department_id")}
                onValueChange={(val) => setValue("department_id", val, { shouldValidate: true })}
              >
                <SelectTrigger
                  id="budget-department"
                  aria-label="Select department"
                  aria-invalid={!!errors.department_id}
                  aria-describedby={errors.department_id ? "dept-error" : undefined}
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
            </div>
            {errors.department_id && (
              <p id="dept-error" role="alert" className="text-xs text-destructive">
                {errors.department_id.message}
              </p>
            )}
          </div>

          {/* Fiscal Year */}
          <div className="space-y-1.5">
            <Label htmlFor="budget-fiscal-year">
              Fiscal Year <span aria-hidden="true" className="text-destructive">*</span>
            </Label>
            <div className="relative">
              <Select
                value={String(fiscalYear)}
                onValueChange={(val) =>
                  setValue("fiscal_year", parseInt(val, 10), { shouldValidate: true })
                }
              >
                <SelectTrigger
                  id="budget-fiscal-year"
                  aria-label="Select fiscal year"
                  aria-invalid={!!errors.fiscal_year}
                  aria-describedby={errors.fiscal_year ? "year-error" : undefined}
                >
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {FISCAL_YEARS.map((yr) => (
                    <SelectItem key={yr} value={String(yr)}>
                      {yr}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            {errors.fiscal_year && (
              <p id="year-error" role="alert" className="text-xs text-destructive">
                {errors.fiscal_year.message}
              </p>
            )}
          </div>

          {/* Currency + Amount (inline) */}
          <div className="grid grid-cols-5 gap-2">
            <div className="col-span-2 space-y-1.5">
              <Label htmlFor="budget-currency">Currency</Label>
              <div className="relative">
                <Select
                  value={currency}
                  onValueChange={(val) => setValue("currency", val, { shouldValidate: true })}
                >
                  <SelectTrigger id="budget-currency" aria-label="Select currency">
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

            <div className="col-span-3 space-y-1.5">
              <Label htmlFor="budget-amount">
                Total Amount <span aria-hidden="true" className="text-destructive">*</span>
              </Label>
              <Input
                id="budget-amount"
                type="number"
                step="0.01"
                min="0.01"
                placeholder="0.00"
                aria-invalid={!!errors.total_amount}
                aria-describedby={errors.total_amount ? "amount-error" : undefined}
                {...register("total_amount")}
              />
              {errors.total_amount && (
                <p id="amount-error" role="alert" className="text-xs text-destructive">
                  {errors.total_amount.message}
                </p>
              )}
            </div>
          </div>
        </form>

        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            onClick={handleClose}
            disabled={createBudget.isPending}
          >
            Cancel
          </Button>
          <Button
            type="submit"
            form="create-budget-form"
            disabled={createBudget.isPending}
          >
            {createBudget.isPending ? "Allocating…" : "Allocate Budget"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
