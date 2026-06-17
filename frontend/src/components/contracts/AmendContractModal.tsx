"use client"

/**
 * AmendContractModal — ShadCN Dialog for amending a contract.
 *
 * Editable fields: title, scope, total_value, end_date, payment_terms.
 * Mandatory reason textarea (min 10 chars).
 *
 * Validates: Requirements 11.5, 22.6
 */

import { useEffect } from 'react';
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
import { Alert, AlertDescription } from '@/components/ui/alert';
import { useAmendContract } from '@/hooks/useContracts';
import {
  amendContractSchema,
  type AmendContractFormData,
} from '@/lib/validations/contracts';
import type { ContractDetail } from '@/types/contract';

interface AmendContractModalProps {
  contract: ContractDetail;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onSuccess?: () => void;
}

export function AmendContractModal({
  contract,
  open,
  onOpenChange,
  onSuccess,
}: AmendContractModalProps) {
  const amendContract = useAmendContract();

  const {
    register,
    handleSubmit,
    reset,
    formState: { errors, isSubmitting },
  } = useForm<AmendContractFormData>({
    resolver: zodResolver(amendContractSchema),
    defaultValues: {
      reason: '',
      title: contract.title,
      scope: contract.scope,
      total_value: parseFloat(contract.total_value) || undefined,
      end_date: contract.end_date,
      payment_terms: contract.payment_terms ?? '',
    },
  });

  // Reset form when contract changes or dialog opens
  useEffect(() => {
    if (open) {
      reset({
        reason: '',
        title: contract.title,
        scope: contract.scope,
        total_value: parseFloat(contract.total_value) || undefined,
        end_date: contract.end_date,
        payment_terms: contract.payment_terms ?? '',
      });
      amendContract.reset();
    }
  }, [open, contract]);

  function handleClose() {
    reset();
    amendContract.reset();
    onOpenChange(false);
  }

  const onSubmit = handleSubmit(async (data) => {
    try {
      await amendContract.mutateAsync({
        id: contract.id,
        payload: {
          reason: data.reason,
          title: data.title,
          scope: data.scope,
          total_value: data.total_value,
          end_date: data.end_date,
          payment_terms: data.payment_terms ?? null,
        },
      });
      onSuccess?.();
      onOpenChange(false);
    } catch {
      // error shown via amendContract.error
    }
  });

  const apiError =
    amendContract.error instanceof Error
      ? amendContract.error.message
      : amendContract.isError
        ? 'Failed to amend contract. Please try again.'
        : null;

  return (
    <Dialog open={open} onOpenChange={handleClose}>
      <DialogContent className="sm:max-w-2xl max-h-[90vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>Amend Contract</DialogTitle>
          <DialogDescription>
            Update contract fields. A documented reason is required and will be
            saved in the amendment history.
          </DialogDescription>
        </DialogHeader>

        <form id="amend-contract-form" onSubmit={onSubmit} noValidate className="space-y-5">
          {apiError && (
            <Alert variant="destructive" role="alert">
              <AlertDescription>{apiError}</AlertDescription>
            </Alert>
          )}

          {/* Reason — mandatory */}
          <div className="space-y-1.5">
            <Label htmlFor="amend-reason">
              Reason for Amendment <span aria-hidden="true" className="text-destructive">*</span>
            </Label>
            <Textarea
              id="amend-reason"
              placeholder="Describe the reason for this amendment (at least 10 characters)…"
              rows={3}
              aria-invalid={!!errors.reason}
              aria-describedby={errors.reason ? 'amend-reason-error' : undefined}
              {...register('reason')}
            />
            {errors.reason && (
              <p id="amend-reason-error" role="alert" className="text-xs text-destructive">
                {errors.reason.message}
              </p>
            )}
          </div>

          {/* Title */}
          <div className="space-y-1.5">
            <Label htmlFor="amend-title">Title</Label>
            <Input
              id="amend-title"
              placeholder="Contract title…"
              aria-invalid={!!errors.title}
              aria-describedby={errors.title ? 'amend-title-error' : undefined}
              {...register('title')}
            />
            {errors.title && (
              <p id="amend-title-error" role="alert" className="text-xs text-destructive">
                {errors.title.message}
              </p>
            )}
          </div>

          {/* Scope */}
          <div className="space-y-1.5">
            <Label htmlFor="amend-scope">Scope</Label>
            <Textarea
              id="amend-scope"
              placeholder="Scope of work or supply…"
              rows={4}
              aria-invalid={!!errors.scope}
              aria-describedby={errors.scope ? 'amend-scope-error' : undefined}
              {...register('scope')}
            />
            {errors.scope && (
              <p id="amend-scope-error" role="alert" className="text-xs text-destructive">
                {errors.scope.message}
              </p>
            )}
          </div>

          {/* Total Value + End Date */}
          <div className="grid grid-cols-2 gap-4">
            <div className="space-y-1.5">
              <Label htmlFor="amend-total-value">Total Value</Label>
              <Input
                id="amend-total-value"
                type="number"
                min={0}
                step="0.01"
                placeholder="0.00"
                aria-invalid={!!errors.total_value}
                aria-describedby={errors.total_value ? 'amend-value-error' : undefined}
                {...register('total_value', { valueAsNumber: true })}
              />
              {errors.total_value && (
                <p id="amend-value-error" role="alert" className="text-xs text-destructive">
                  {errors.total_value.message}
                </p>
              )}
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="amend-end-date">End Date</Label>
              <Input
                id="amend-end-date"
                type="date"
                aria-invalid={!!errors.end_date}
                aria-describedby={errors.end_date ? 'amend-end-date-error' : undefined}
                {...register('end_date')}
              />
              {errors.end_date && (
                <p id="amend-end-date-error" role="alert" className="text-xs text-destructive">
                  {errors.end_date.message}
                </p>
              )}
            </div>
          </div>

          {/* Payment Terms */}
          <div className="space-y-1.5">
            <Label htmlFor="amend-payment-terms">Payment Terms</Label>
            <Textarea
              id="amend-payment-terms"
              placeholder="E.g. Net 30, milestone-based…"
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
            disabled={isSubmitting || amendContract.isPending}
          >
            Cancel
          </Button>
          <Button
            type="submit"
            form="amend-contract-form"
            disabled={isSubmitting || amendContract.isPending}
          >
            {amendContract.isPending ? 'Saving…' : 'Save Amendment'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
