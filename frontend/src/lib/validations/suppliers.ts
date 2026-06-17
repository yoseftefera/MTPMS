/**
 * Zod validation schemas for Supplier Management forms.
 *
 * Covers: blacklist supplier, upload document, supplier registration (public).
 *
 * Validates: Requirements 7.1, 7.4, 22.7
 */

import { z } from 'zod';

// ─── Business categories ──────────────────────────────────────────────────────

export const BUSINESS_CATEGORIES = [
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

export type BusinessCategory = (typeof BUSINESS_CATEGORIES)[number];

// ─── Document types ───────────────────────────────────────────────────────────

export const SUPPLIER_DOCUMENT_TYPES = [
  'tin_certificate',
  'vat_certificate',
  'business_license',
  'performance_bond',
  'other',
] as const;

export const DOCUMENT_TYPE_LABELS: Record<(typeof SUPPLIER_DOCUMENT_TYPES)[number], string> = {
  tin_certificate: 'TIN Certificate',
  vat_certificate: 'VAT Certificate',
  business_license: 'Business License',
  performance_bond: 'Performance Bond',
  other: 'Other',
};

// ─── Blacklist schema ─────────────────────────────────────────────────────────

export const blacklistSupplierSchema = z.object({
  reason: z
    .string({ required_error: 'Reason is required' })
    .min(10, 'Please provide at least 10 characters explaining the reason')
    .max(1000, 'Reason must be less than 1000 characters')
    .trim(),
});

export type BlacklistSupplierFormData = z.infer<typeof blacklistSupplierSchema>;

// ─── Reject schema ────────────────────────────────────────────────────────────

export const rejectSupplierSchema = z.object({
  reason: z
    .string()
    .max(1000, 'Reason must be less than 1000 characters')
    .trim()
    .optional(),
});

export type RejectSupplierFormData = z.infer<typeof rejectSupplierSchema>;

// ─── Upload document schema ───────────────────────────────────────────────────

export const uploadDocumentSchema = z.object({
  document_type: z.enum(SUPPLIER_DOCUMENT_TYPES, {
    required_error: 'Document type is required',
  }),
  expires_at: z
    .string()
    .regex(/^\d{4}-\d{2}-\d{2}$/, 'Please enter a valid date (YYYY-MM-DD)')
    .nullable()
    .optional(),
});

export type UploadDocumentFormData = z.infer<typeof uploadDocumentSchema>;

// ─── Supplier registration schema (public) ────────────────────────────────────

export const supplierRegistrationSchema = z.object({
  organization_name: z
    .string({ required_error: 'Organization name is required' })
    .min(2, 'Organization name must be at least 2 characters')
    .max(255, 'Organization name must be less than 255 characters')
    .trim(),
  contact_name: z
    .string({ required_error: 'Contact name is required' })
    .min(2, 'Contact name must be at least 2 characters')
    .max(255, 'Contact name must be less than 255 characters')
    .trim(),
  contact_email: z
    .string({ required_error: 'Contact email is required' })
    .email('Please enter a valid email address')
    .max(255, 'Email must be less than 255 characters')
    .toLowerCase()
    .trim(),
  contact_phone: z
    .string()
    .max(50, 'Phone number must be less than 50 characters')
    .trim()
    .nullable()
    .optional(),
  business_category: z
    .string({ required_error: 'Business category is required' })
    .min(1, 'Please select a business category')
    .max(100, 'Business category must be less than 100 characters'),
});

export type SupplierRegistrationFormData = z.infer<typeof supplierRegistrationSchema>;
