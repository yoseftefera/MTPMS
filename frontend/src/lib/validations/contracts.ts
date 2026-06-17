/**
 * Zod validation schemas for Contract forms.
 *
 * Validates: Requirements 11.1, 11.5, 22.7
 */

import { z } from 'zod';

// ─── Constants ────────────────────────────────────────────────────────────────

export const CURRENCIES = ['USD', 'EUR', 'GBP', 'ETB', 'KES', 'NGN', 'GHS', 'ZAR'];

// ─── Create contract ──────────────────────────────────────────────────────────

export const createContractSchema = z
  .object({
    supplier_id: z
      .string({ required_error: 'Supplier is required' })
      .min(1, 'Supplier is required'),
    purchase_order_id: z.string().optional().nullable(),
    title: z
      .string({ required_error: 'Title is required' })
      .min(1, 'Title is required')
      .max(255, 'Title must be under 255 characters'),
    scope: z
      .string({ required_error: 'Scope is required' })
      .min(1, 'Scope is required')
      .max(5000, 'Scope must be under 5000 characters'),
    total_value: z
      .number({ required_error: 'Total value is required', invalid_type_error: 'Total value must be a number' })
      .positive('Total value must be greater than 0'),
    currency: z
      .string()
      .length(3, 'Currency must be a 3-letter ISO code')
      .default('USD'),
    start_date: z
      .string({ required_error: 'Start date is required' })
      .min(1, 'Start date is required'),
    end_date: z
      .string({ required_error: 'End date is required' })
      .min(1, 'End date is required'),
    payment_terms: z
      .string()
      .max(2000, 'Payment terms must be under 2000 characters')
      .optional()
      .nullable(),
  })
  .refine(
    (data) => {
      if (!data.start_date || !data.end_date) return true;
      return new Date(data.end_date) > new Date(data.start_date);
    },
    {
      message: 'End date must be after start date',
      path: ['end_date'],
    },
  );

export type CreateContractFormData = z.infer<typeof createContractSchema>;

// ─── Amend contract ───────────────────────────────────────────────────────────

export const amendContractSchema = z.object({
  reason: z
    .string({ required_error: 'Reason is required' })
    .min(10, 'Reason must be at least 10 characters')
    .max(2000, 'Reason must be under 2000 characters'),
  title: z
    .string()
    .max(255, 'Title must be under 255 characters')
    .optional(),
  scope: z
    .string()
    .max(5000, 'Scope must be under 5000 characters')
    .optional(),
  total_value: z
    .number({ invalid_type_error: 'Total value must be a number' })
    .positive('Total value must be greater than 0')
    .optional(),
  end_date: z.string().optional(),
  payment_terms: z
    .string()
    .max(2000, 'Payment terms must be under 2000 characters')
    .optional()
    .nullable(),
});

export type AmendContractFormData = z.infer<typeof amendContractSchema>;

// ─── Termination ──────────────────────────────────────────────────────────────

export const terminateContractSchema = z.object({
  reason: z
    .string({ required_error: 'Reason is required' })
    .min(10, 'Reason must be at least 10 characters')
    .max(2000, 'Reason must be under 2000 characters'),
});

export type TerminateContractFormData = z.infer<typeof terminateContractSchema>;
