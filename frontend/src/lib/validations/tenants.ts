/**
 * Zod validation schemas for Tenant Management forms.
 *
 * Covers: register tenant form.
 *
 * Validates: Requirements 1.6, 22.7
 */

import { z } from 'zod';

// ─── Register tenant schema ───────────────────────────────────────────────────

export const registerTenantSchema = z.object({
  name: z
    .string({ required_error: 'Organization name is required' })
    .min(2, 'Name must be at least 2 characters')
    .max(255, 'Name must be less than 255 characters')
    .trim(),
  subdomain: z
    .string({ required_error: 'Subdomain is required' })
    .min(2, 'Subdomain must be at least 2 characters')
    .max(100, 'Subdomain must be less than 100 characters')
    .regex(
      /^[a-z0-9][a-z0-9-]*[a-z0-9]$|^[a-z0-9]$/,
      'Subdomain may only contain lowercase letters, numbers, and hyphens, and must not start or end with a hyphen',
    )
    .trim(),
  admin_email: z
    .string({ required_error: 'Admin email is required' })
    .email('Please enter a valid email address')
    .max(255, 'Email must be less than 255 characters')
    .toLowerCase()
    .trim(),
  tenant_code: z
    .string({ required_error: 'Tenant code is required' })
    .min(2, 'Tenant code must be at least 2 characters')
    .max(10, 'Tenant code must be at most 10 characters')
    .regex(
      /^[A-Z0-9]+$/,
      'Tenant code may only contain uppercase letters and numbers',
    )
    .trim(),
});

export type RegisterTenantFormData = z.infer<typeof registerTenantSchema>;
