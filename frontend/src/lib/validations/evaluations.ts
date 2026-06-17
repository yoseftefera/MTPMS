/**
 * Zod validation schemas for Bid Evaluation forms.
 *
 * Covers:
 *   - Criteria configuration (Procurement_Officer / Tenant_Admin)
 *   - Winner selection with justification (Procurement_Officer / Tenant_Admin)
 *
 * Validates: Requirements 9.1, 9.5, 22.7
 */

import { z } from 'zod';

// ─── Single criterion row ─────────────────────────────────────────────────────

export const criterionRowSchema = z.object({
  name: z
    .string({ required_error: 'Criterion name is required' })
    .min(1, 'Criterion name is required')
    .max(255, 'Criterion name must be under 255 characters')
    .trim(),
  weight: z
    .number({ required_error: 'Weight is required', invalid_type_error: 'Weight must be a number' })
    .min(1, 'Weight must be at least 1')
    .max(100, 'Weight cannot exceed 100'),
  description: z.string().max(500, 'Description must be under 500 characters').optional(),
});

export type CriterionRowData = z.infer<typeof criterionRowSchema>;

// ─── Criteria configuration form ──────────────────────────────────────────────

export const criteriaConfigSchema = z
  .object({
    criteria: z
      .array(criterionRowSchema)
      .min(1, 'At least one criterion is required')
      .max(20, 'Maximum 20 criteria allowed'),
  })
  .refine(
    (data) => {
      const total = data.criteria.reduce((sum, c) => sum + (c.weight ?? 0), 0);
      return Math.abs(total - 100) < 0.01; // allow floating-point tolerance
    },
    {
      message: 'Criteria weights must sum to exactly 100',
      path: ['criteria'],
    },
  );

export type CriteriaConfigFormData = z.infer<typeof criteriaConfigSchema>;

// ─── Winner justification form ────────────────────────────────────────────────

export const winnerJustificationSchema = z.object({
  justification: z
    .string({ required_error: 'Justification is required' })
    .min(10, 'Justification must be at least 10 characters')
    .max(2000, 'Justification must be under 2000 characters')
    .trim(),
});

export type WinnerJustificationFormData = z.infer<typeof winnerJustificationSchema>;
