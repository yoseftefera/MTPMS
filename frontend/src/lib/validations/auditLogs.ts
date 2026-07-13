/**
 * Zod validation schemas for the Audit Log filter form.
 *
 * Validates: Requirements 17.7, 22.6
 */

import { z } from 'zod';

// ─── Known action types ───────────────────────────────────────────────────────

export const ACTION_TYPES = [
  'create',
  'update',
  'delete',
  'login',
  'logout',
  'login_failed',
  'account_locked',
  'password_reset',
  'approve',
  'reject',
  'return',
  'submit',
  'cancel',
  'publish',
  'award',
  'blacklist',
  'verify',
] as const;

export type ActionType = (typeof ACTION_TYPES)[number];

export const ACTION_TYPE_LABELS: Record<ActionType, string> = {
  create: 'Create',
  update: 'Update',
  delete: 'Delete',
  login: 'Login',
  logout: 'Logout',
  login_failed: 'Login Failed',
  account_locked: 'Account Locked',
  password_reset: 'Password Reset',
  approve: 'Approve',
  reject: 'Reject',
  return: 'Return for Revision',
  submit: 'Submit',
  cancel: 'Cancel',
  publish: 'Publish',
  award: 'Award',
  blacklist: 'Blacklist',
  verify: 'Verify',
};

// ─── Known entity types ───────────────────────────────────────────────────────

export const ENTITY_TYPES = [
  'user',
  'tenant',
  'department',
  'budget',
  'purchase_request',
  'approval_workflow',
  'approval',
  'supplier',
  'tender',
  'bid',
  'bid_evaluation',
  'purchase_order',
  'contract',
  'goods_receipt',
  'inventory',
  'invoice',
  'payment',
  'notification',
  'file',
] as const;

export type EntityType = (typeof ENTITY_TYPES)[number];

export const ENTITY_TYPE_LABELS: Record<EntityType, string> = {
  user: 'User',
  tenant: 'Tenant',
  department: 'Department',
  budget: 'Budget',
  purchase_request: 'Purchase Request',
  approval_workflow: 'Approval Workflow',
  approval: 'Approval',
  supplier: 'Supplier',
  tender: 'Tender',
  bid: 'Bid',
  bid_evaluation: 'Bid Evaluation',
  purchase_order: 'Purchase Order',
  contract: 'Contract',
  goods_receipt: 'Goods Receipt',
  inventory: 'Inventory',
  invoice: 'Invoice',
  payment: 'Payment',
  notification: 'Notification',
  file: 'File',
};

// ─── Filter form schema ───────────────────────────────────────────────────────

export const auditLogFilterSchema = z.object({
  user: z
    .string()
    .max(255, 'User search must be less than 255 characters')
    .optional(),

  action_type: z
    .string()
    .optional(),

  entity_type: z
    .string()
    .optional(),

  ip_address: z
    .string()
    .max(45, 'IP address must be less than 45 characters')
    .optional(),

  date_from: z
    .string()
    .optional()
    .refine(
      (val) => !val || !isNaN(Date.parse(val)),
      { message: 'From date must be a valid date' },
    ),

  date_to: z
    .string()
    .optional()
    .refine(
      (val) => !val || !isNaN(Date.parse(val)),
      { message: 'To date must be a valid date' },
    ),
}).refine(
  (data) => {
    if (data.date_from && data.date_to) {
      return new Date(data.date_from) <= new Date(data.date_to);
    }
    return true;
  },
  {
    message: '"From" date must be on or before "To" date',
    path: ['date_to'],
  },
);

export type AuditLogFilterFormData = z.infer<typeof auditLogFilterSchema>;
