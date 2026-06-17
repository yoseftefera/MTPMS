/**
 * Zod validation schemas for User Management forms.
 *
 * Covers: create user, edit user.
 *
 * Validates: Requirements 4.1, 4.8, 22.7
 */

import { z } from 'zod';

// ─── Available roles ──────────────────────────────────────────────────────────
// Matches the 8 system roles defined in Requirement 3.1.
// System_Admin is excluded from the Tenant-facing interface (Requirement 3.5).

export const TENANT_ROLES = [
  'Tenant_Admin',
  'Procurement_Officer',
  'Finance_Officer',
  'Store_Manager',
  'Committee_Member',
  'Department_Staff',
  'Supplier',
] as const;

export type TenantRole = (typeof TENANT_ROLES)[number];

export const ROLE_LABELS: Record<TenantRole, string> = {
  Tenant_Admin: 'Tenant Admin',
  Procurement_Officer: 'Procurement Officer',
  Finance_Officer: 'Finance Officer',
  Store_Manager: 'Store Manager',
  Committee_Member: 'Committee Member',
  Department_Staff: 'Department Staff',
  Supplier: 'Supplier',
};

// ─── Create user schema ───────────────────────────────────────────────────────

export const createUserSchema = z.object({
  name: z
    .string({ required_error: 'Name is required' })
    .min(2, 'Name must be at least 2 characters')
    .max(255, 'Name must be less than 255 characters')
    .trim(),
  email: z
    .string({ required_error: 'Email is required' })
    .email('Please enter a valid email address')
    .max(255, 'Email must be less than 255 characters')
    .toLowerCase()
    .trim(),
  role: z.enum(TENANT_ROLES, {
    required_error: 'Role is required',
    invalid_type_error: 'Please select a valid role',
  }),
  department_id: z
    .string()
    .uuid('Please select a valid department')
    .nullable()
    .optional(),
  phone: z
    .string()
    .max(50, 'Phone number must be less than 50 characters')
    .nullable()
    .optional(),
});

export type CreateUserFormData = z.infer<typeof createUserSchema>;

// ─── Edit user schema ─────────────────────────────────────────────────────────

export const editUserSchema = z.object({
  name: z
    .string({ required_error: 'Name is required' })
    .min(2, 'Name must be at least 2 characters')
    .max(255, 'Name must be less than 255 characters')
    .trim(),
  email: z
    .string({ required_error: 'Email is required' })
    .email('Please enter a valid email address')
    .max(255, 'Email must be less than 255 characters')
    .toLowerCase()
    .trim(),
  role: z.enum(TENANT_ROLES, {
    required_error: 'Role is required',
    invalid_type_error: 'Please select a valid role',
  }),
  department_id: z
    .string()
    .uuid('Please select a valid department')
    .nullable()
    .optional(),
  phone: z
    .string()
    .max(50, 'Phone number must be less than 50 characters')
    .nullable()
    .optional(),
});

export type EditUserFormData = z.infer<typeof editUserSchema>;
