"use client"

/**
 * CreateContractForm — ShadCN Dialog for creating a new draft contract.
 *
 * Fields:
 *   - supplier_id (select active suppliers)
 *   - purchase_order_id (optional UUID text)
 *   - title
 *   - scope (textarea)
 *   - total_value (number)
 *   - currency (select)
 *   - start_date
 *   - end_date
 *   - payment_terms (optional textarea)
 *
 * Validates: Requirements 11.1, 22.6
 */

import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { useCreateContract } from '@/hooks/useContracts';
import {
  createContractSchema,
  type CreateContractFormData,
  CURRENCIES,
} from '@/lib/validations/contracts';
import type { Supplier } from '@/types/models.types';

interface CreateContractFormProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  suppliers: Supplier[];
  onSuccess?: () => void;
}

export function CreateContractForm({
  open,
  onOpenChange,
  suppliers,
  onSuccess,
}: CreateContractFormProps) {
  const createContract = useCreateContract();

  const {
    register,
    handleSubmit,
    setValue,
    watch,
    reset,
    formState: { errors, isSubmitting },
  } = useForm<CreateContractFormData>({
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    resolver: zodResolver(createContractSchema) as any,
    defaultValues: {
      currency: 'USD',
      payment_terms: '',
      purchase_order_id: '',
    },
  });

  const selectedSupplierId = watch('supplier_id');
  const selectedCurrency = watch('currency');

  function handleClose() {
    reset();
    createContract.reset();
    onOpenChange(false);
  }

  const onSubmit = handleSubmit(async (data) => {
    try {
      await createContract.mutateAsync({
        supplier_id: data.supplier_id,
        purchase_order_id: data.purchase_order_id || null,
        title: data.title,
        scope: data.scope,
        total_value: data.total_value,
        currency: data.currency,
        start_date: data.start_date,
        end_date: data.end_date,
        payment_terms: data.payment_terms || null,
      });
      reset();
      onSuccess?.();
      onOpenChange(false);
    } catch {
      // error shown via createContract.error
    }
  });

  const apiError =
    createContract.error instanceof Error
      ? createContract.error.message
      : createContract.isError
        ? 'Failed to create contract. Please try again.'
        : null;

  return (
    <Dialog open={open} onOpenChange={handleClose}>
      <DialogContent className="sm:max-w-2xl max-h-[90vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>Create Contract</DialogTitle>
          <DialogDescription>
            Create a new draft contract linked to a supplier.
          </DialogDescription>
        </DialogHeader>

        <form id="create-contract-form" onSubmit={onSubmit} noValidate className="space-y-5">
          {apiError && (
            <Alert variant="destructive" role="alert">
              <AlertDescription>{apiError}</AlertDescription>
            </Alert>
          )}

          {/* Supplier */}
          <div className="space-y-1.5">
            <Label htmlFor="contract-supplier">
              Supplier <span aria-hidden="true" className="text-destructive">*</span>
            </Label>
            <Select
              value={selectedSupplierId ?? ''}
              onValueChange={(val) => setValue('supplier_id', val, { shouldValidate: true })}
            >
              <SelectTrigger
                id="contract-supplier"
                aria-invalid={!!errors.supplier_id}
                aria-describedby={errors.supplier_id ? 'supplier-error' : undefined}
              >
                <SelectValue placeholder="Select a supplier…" />
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
              <p id="supplier-error" role="alert" className="text-xs text-destructive">
                {errors.supplier_id.message}
              </p>
            )}
          </div>

          {/* Purchase Order ID (optional) */}
          <div className="space-y-1.5">
            <Label htmlFor="contract-po-id">Purchase Order ID (optional)</Label>
            <Input
              id="contract-po-id"
              placeholder="UUID of linked purchase order…"
              {...register('purchase_order_id')}
            />
          </div>

          {/* Title */}
          <div className="space-y-1.5">
            <Label htmlFor="contract-title">
              Title <span aria-hidden="true" className="text-destructive">*</span>
            </Label>
            <Input
              id="contract-title"
              placeholder="Contract title…"
              aria-invalid={!!errors.title}
              aria-describedby={errors.title ? 'title-error' : undefined}
              {...register('title')}
            />
            {errors.title && (
              <p id="title-error" role="alert" className="text-xs text-destructive">
                {errors.title.message}
              </p>
            )}
          </div>

          {/* Scope */}
          <div className="space-y-1.5">
            <Label htmlFor="contract-scope">
              Scope <span aria-hidden="true" className="text-destructive">*</span>
            </Label>
            <Textarea
              id="contract-scope"
              placeholder="Describe the scope of work or supply…"
              rows={4}
              aria-invalid={!!errors.scope}
              aria-describedby={errors.scope ? 'scope-error' : undefined}
              {...register('scope')}
            />
            {errors.scope && (
              <p id="scope-error" role="alert" className="text-xs text-destructive">
                {errors.scope.message}
              </p>
            )}
          </div>

          {/* Total Value + Currency */}
          <div className="grid grid-cols-2 gap-4">
            <div className="space-y-1.5">
              <Label htmlFor="contract-total-value">
                Total Value <span aria-hidden="true" className="text-destructive">*</span>
              </Label>
              <Input
                id="contract-total-value"
                type="number"
                min={0}
                step="0.01"
                placeholder="0.00"
                aria-invalid={!!errors.total_value}
                aria-describedby={errors.total_value ? 'total-value-error' : undefined}
                {...register('total_value', { valueAsNumber: true })}
              />
              {errors.total_value && (
                <p id="total-value-error" role="alert" className="text-xs text-destructive">
                  {errors.total_value.message}
                </p>
              )}
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="contract-currency">Currency</Label>
              <Select
                value={selectedCurrency ?? 'USD'}
                onValueChange={(val) => setValue('currency', val, { shouldValidate: true })}
              >
                <SelectTrigger id="contract-currency">
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
            </div>
          </div>

          {/* Start Date + End Date */}
          <div className="grid grid-cols-2 gap-4">
            <div className="space-y-1.5">
              <Label htmlFor="contract-start-date">
                Start Date <span aria-hidden="true" className="text-destructive">*</span>
              </Label>
              <Input
                id="contract-start-date"
                type="date"
                aria-invalid={!!errors.start_date}
                aria-describedby={errors.start_date ? 'start-date-error' : undefined}
                {...register('start_date')}
              />
              {errors.start_date && (
                <p id="start-date-error" role="alert" className="text-xs text-destructive">
                  {errors.start_date.message}
                </p>
              )}
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="contract-end-date">
                End Date <span aria-hidden="true" className="text-destructive">*</span>
              </Label>
              <Input
                id="contract-end-date"
                type="date"
                aria-invalid={!!errors.end_date}
                aria-describedby={errors.end_date ? 'end-date-error' : undefined}
                {...register('end_date')}
              />
              {errors.end_date && (
                <p id="end-date-error" role="alert" className="text-xs text-destructive">
                  {errors.end_date.message}
                </p>
              )}
            </div>
          </div>

          {/* Payment Terms */}
          <div className="space-y-1.5">
            <Label htmlFor="contract-payment-terms">Payment Terms (optional)</Label>
            <Textarea
              id="contract-payment-terms"
              placeholder="E.g. Net 30, 50% upfront, milestone-based…"
              rows={3}
              {...register('payment_terms')}
            />
          </div>
        </form>

        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            onClick={handleClose}
            disabled={isSubmitting || createContract.isPending}
          >
            Cancel
          </Button>
          <Button
            type="submit"
            form="create-contract-form"
            disabled={isSubmitting || createContract.isPending}
          >
            {createContract.isPending ? 'Creating…' : 'Create Contract'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
