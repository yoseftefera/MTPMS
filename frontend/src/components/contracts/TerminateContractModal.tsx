"use client"

/**
 * TerminateContractModal — ShadCN Dialog for terminating an active contract.
 *
 * Mandatory reason textarea (Zod min 10 chars).
 *
 * Validates: Requirements 11.10, 22.6
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
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { useTerminateContract } from '@/hooks/useContracts';
import {
  terminateContractSchema,
  type TerminateContractFormData,
} from '@/lib/validations/contracts';

interface TerminateContractModalProps {
  contractId: string;
  contractTitle: string;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onSuccess?: () => void;
}

export function TerminateContractModal({
  contractId,
  contractTitle,
  open,
  onOpenChange,
  onSuccess,
}: TerminateContractModalProps) {
  const terminateContract = useTerminateContract();

  const {
    register,
    handleSubmit,
    reset,
    formState: { errors, isSubmitting },
  } = useForm<TerminateContractFormData>({
    resolver: zodResolver(terminateContractSchema),
    defaultValues: { reason: '' },
  });

  useEffect(() => {
    if (open) {
      reset({ reason: '' });
      terminateContract.reset();
    }
  }, [open]);

  function handleClose() {
    reset();
    terminateContract.reset();
    onOpenChange(false);
  }

  const onSubmit = handleSubmit(async (data) => {
    try {
      await terminateContract.mutateAsync({
        id: contractId,
        payload: { reason: data.reason },
      });
      onSuccess?.();
      onOpenChange(false);
    } catch {
      // error shown via terminateContract.error
    }
  });

  const apiError =
    terminateContract.error instanceof Error
      ? terminateContract.error.message
      : terminateContract.isError
        ? 'Failed to terminate contract. Please try again.'
        : null;

  return (
    <Dialog open={open} onOpenChange={handleClose}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Terminate Contract</DialogTitle>
          <DialogDescription>
            You are about to terminate{' '}
            <strong className="font-medium">{contractTitle}</strong>. This
            action is recorded in the audit log and cannot be undone. Please
            provide a reason.
          </DialogDescription>
        </DialogHeader>

        <form
          id="terminate-contract-form"
          onSubmit={onSubmit}
          noValidate
          className="space-y-4"
        >
          {apiError && (
            <Alert variant="destructive" role="alert">
              <AlertDescription>{apiError}</AlertDescription>
            </Alert>
          )}

          <div className="space-y-1.5">
            <Label htmlFor="terminate-reason">
              Reason <span aria-hidden="true" className="text-destructive">*</span>
            </Label>
            <Textarea
              id="terminate-reason"
              placeholder="Provide a reason for termination (at least 10 characters)…"
              rows={4}
              aria-invalid={!!errors.reason}
              aria-describedby={errors.reason ? 'terminate-reason-error' : undefined}
              {...register('reason')}
            />
            {errors.reason && (
              <p id="terminate-reason-error" role="alert" className="text-xs text-destructive">
                {errors.reason.message}
              </p>
            )}
          </div>
        </form>

        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            onClick={handleClose}
            disabled={isSubmitting || terminateContract.isPending}
          >
            Cancel
          </Button>
          <Button
            type="submit"
            form="terminate-contract-form"
            variant="destructive"
            disabled={isSubmitting || terminateContract.isPending}
          >
            {terminateContract.isPending ? 'Terminating…' : 'Terminate Contract'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
