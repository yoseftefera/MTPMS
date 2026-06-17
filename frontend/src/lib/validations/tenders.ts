/**
 * Zod validation schemas for Tender & Bidding forms.
 *
 * Covers:
 *   - Create / Edit Tender (Procurement_Officer)
 *   - Submit / Revise Bid (Supplier)
 *   - Cancel Tender (reason)
 *   - Extend Deadline
 *
 * Validates: Requirements 8.1, 8.3, 22.7
 */

import { z } from 'zod';

// ─── Shared constants ─────────────────────────────────────────────────────────

export const TENDER_TYPES = [
  { value: 'open', label: 'Open' },
  { value: 'restricted', label: 'Restricted' },
  { value: 'single_source', label: 'Single Source' },
] as const;

export const TENDER_CATEGORIES = [
  'IT & Technology',
  'Office Supplies',
  'Construction & Engineering',
  'Medical & Pharmaceuticals',
  'Food & Beverages',
  'Logistics & Transport',
  'Consulting & Professional Services',
  'Cleaning & Facilities',
  'Security Services',
  'Marketing & Advertising',
  'Manufacturing',
  'Other',
] as const;

export const CURRENCIES = ['USD', 'EUR', 'GBP', 'ETB', 'KES', 'NGN', 'GHS', 'ZAR'] as const;

// ─── Create / Edit Tender schema ──────────────────────────────────────────────

export const tenderSchema = z.object({
  title: z
    .string({ required_error: 'Title is required' })
    .min(3, 'Title must be at least 3 characters')
    .max(255, 'Title must be under 255 characters')
    .trim(),
  description: z
    .string({ required_error: 'Description is required' })
    .min(10, 'Please provide at least 10 characters of description')
    .max(5000, 'Description must be under 5000 characters')
    .trim(),
  category: z
    .string({ required_error: 'Category is required' })
    .min(1, 'Please select a category'),
  tender_type: z.enum(['open', 'restricted', 'single_source'], {
    required_error: 'Tender type is required',
  }),
  estimated_value: z
    .string({ required_error: 'Estimated value is required' })
    .refine((v) => {
      const n = parseFloat(v);
      return !isNaN(n) && n > 0;
    }, 'Estimated value must be a positive number'),
  submission_deadline: z
    .string({ required_error: 'Submission deadline is required' })
    .min(1, 'Submission deadline is required')
    .refine((v) => {
      const d = new Date(v);
      return !isNaN(d.getTime()) && d > new Date();
    }, 'Submission deadline must be a future date and time'),
  currency: z
    .string()
    .length(3, 'Currency must be a 3-letter ISO code')
    .default('USD'),
});

export type TenderFormData = z.infer<typeof tenderSchema>;

// ─── Cancel Tender schema ─────────────────────────────────────────────────────

export const cancelTenderSchema = z.object({
  reason: z
    .string({ required_error: 'Cancellation reason is required' })
    .min(10, 'Please provide at least 10 characters explaining the reason')
    .max(1000, 'Reason must be under 1000 characters')
    .trim(),
});

export type CancelTenderFormData = z.infer<typeof cancelTenderSchema>;

// ─── Extend Deadline schema ───────────────────────────────────────────────────

export const extendDeadlineSchema = z.object({
  submission_deadline: z
    .string({ required_error: 'New deadline is required' })
    .min(1, 'New deadline is required')
    .refine((v) => {
      const d = new Date(v);
      return !isNaN(d.getTime()) && d > new Date();
    }, 'New deadline must be a future date and time'),
});

export type ExtendDeadlineFormData = z.infer<typeof extendDeadlineSchema>;

// ─── Submit / Revise Bid schema ───────────────────────────────────────────────

export const bidSchema = z.object({
  total_amount: z
    .string({ required_error: 'Total bid amount is required' })
    .refine((v) => {
      const n = parseFloat(v);
      return !isNaN(n) && n > 0;
    }, 'Bid amount must be a positive number'),
  currency: z
    .string()
    .length(3, 'Currency must be a 3-letter ISO code')
    .default('USD'),
  delivery_days: z
    .number({ required_error: 'Delivery days is required', invalid_type_error: 'Delivery days must be a number' })
    .int('Delivery days must be a whole number')
    .min(1, 'Delivery days must be at least 1')
    .max(3650, 'Delivery days must be under 3650'),
  technical_notes: z
    .string()
    .max(3000, 'Technical notes must be under 3000 characters')
    .optional(),
});

export type BidFormData = z.infer<typeof bidSchema>;
