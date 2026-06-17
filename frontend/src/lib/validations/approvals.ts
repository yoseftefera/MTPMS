/**
 * Zod validation schemas for Approval action forms.
 *
 * Covers: approve (optional comment), reject (required reason),
 * return for revision (required comments).
 *
 * Validates: Requirements 22.5, 22.7
 */

import { z } from 'zod';

// ─── Approve schema ───────────────────────────────────────────────────────────

export const ApproveSchema = z.object({
  comment: z.string().max(1000, 'Comment must be less than 1000 characters').optional(),
});

export type ApproveFormData = z.infer<typeof ApproveSchema>;

// ─── Reject schema ────────────────────────────────────────────────────────────

export const RejectSchema = z.object({
  reason: z
    .string({ required_error: 'Reason is required' })
    .min(10, 'Reason must be at least 10 characters')
    .max(1000, 'Reason must be less than 1000 characters')
    .trim(),
});

export type RejectFormData = z.infer<typeof RejectSchema>;

// ─── Return for revision schema ───────────────────────────────────────────────

export const ReturnSchema = z.object({
  comments: z
    .string({ required_error: 'Comments are required' })
    .min(10, 'Comments must be at least 10 characters')
    .max(1000, 'Comments must be less than 1000 characters')
    .trim(),
});

export type ReturnFormData = z.infer<typeof ReturnSchema>;
