/**
 * Zod validation schemas for Budget Management forms.
 *
 * Covers: create budget, update budget, transfer budget.
 *
 * Validates: Requirements 13.1, 13.10, 22.7
 */

import { z } from 'zod';

const currentYear = new Date().getFullYear();

// ─── Create / Allocate budget ─────────────────────────────────────────────────

export const createBudgetSchema = z.object({
  department_id: z
    .string({ required_error: 'Department is required' })
    .uuid('Please select a valid department'),
  fiscal_year: z
    .number({ required_error: 'Fiscal year is required', invalid_type_error: 'Fiscal year must be a number' })
    .int('Fiscal year must be a whole number')
    .min(2000, 'Fiscal year must be 2000 or later')
    .max(2100, 'Fiscal year must be 2100 or earlier'),
  total_amount: z
    .string({ required_error: 'Total amount is required' })
    .refine(
      (v) => {
        const n = parseFloat(v);
        return !isNaN(n) && n > 0;
      },
      { message: 'Amount must be a positive number' },
    ),
  currency: z
    .string()
    .length(3, 'Currency must be a 3-letter ISO code')
    .toUpperCase(),
});

export type CreateBudgetFormData = z.infer<typeof createBudgetSchema>;

// ─── Transfer budget ──────────────────────────────────────────────────────────

export const transferBudgetSchema = z
  .object({
    from_budget_id: z
      .string({ required_error: 'Source budget is required' })
      .uuid('Please select a valid source budget'),
    to_budget_id: z
      .string({ required_error: 'Destination budget is required' })
      .uuid('Please select a valid destination budget'),
    amount: z
      .string({ required_error: 'Transfer amount is required' })
      .refine(
        (v) => {
          const n = parseFloat(v);
          return !isNaN(n) && n > 0;
        },
        { message: 'Amount must be a positive number' },
      ),
    note: z.string().max(500, 'Note must be under 500 characters').optional(),
  })
  .refine((data) => data.from_budget_id !== data.to_budget_id, {
    message: 'Source and destination budgets must be different',
    path: ['to_budget_id'],
  });

export type TransferBudgetFormData = z.infer<typeof transferBudgetSchema>;
