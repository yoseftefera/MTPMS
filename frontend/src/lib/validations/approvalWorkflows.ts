/**
 * Zod validation schemas for Approval Workflow configuration forms.
 *
 * Covers: workflow level, create workflow, update workflow.
 *
 * Validates: Requirements 6.8, 22.7
 */

import { z } from 'zod';

// ─── Document types ───────────────────────────────────────────────────────────

export const DOCUMENT_TYPES = [
  'purchase_request',
  'tender',
  'purchase_order',
  'contract',
  'invoice',
] as const;

export type DocumentType = (typeof DOCUMENT_TYPES)[number];

export const DOCUMENT_TYPE_LABELS: Record<DocumentType, string> = {
  purchase_request: 'Purchase Request',
  tender: 'Tender',
  purchase_order: 'Purchase Order',
  contract: 'Contract',
  invoice: 'Invoice',
};

// ─── Approver roles ───────────────────────────────────────────────────────────

export const APPROVER_ROLES = [
  'Tenant_Admin',
  'Procurement_Officer',
  'Finance_Officer',
  'Store_Manager',
  'Committee_Member',
  'Department_Staff',
  'Supplier',
  'Auditor',
] as const;

export type ApproverRole = (typeof APPROVER_ROLES)[number];

export const APPROVER_ROLE_LABELS: Record<ApproverRole, string> = {
  Tenant_Admin: 'Tenant Admin',
  Procurement_Officer: 'Procurement Officer',
  Finance_Officer: 'Finance Officer',
  Store_Manager: 'Store Manager',
  Committee_Member: 'Committee Member',
  Department_Staff: 'Department Staff',
  Supplier: 'Supplier',
  Auditor: 'Auditor',
};

// ─── Workflow level schema ────────────────────────────────────────────────────

export const WorkflowLevelSchema = z
  .object({
    level_order: z
      .number({ required_error: 'Level order is required' })
      .int()
      .min(1, 'Level order must be at least 1')
      .max(10, 'Level order must be at most 10'),
    approver_type: z.enum(['role', 'user'], {
      required_error: 'Approver type is required',
    }),
    approver_role: z.string().nullable().optional(),
    approver_user_id: z.string().uuid('Please select a valid user').nullable().optional(),
    // Use explicit non-default so the inferred type is `boolean`, not `boolean | undefined`
    is_parallel: z.boolean(),
    escalation_hours: z.number().int().min(1).max(720),
  })
  .superRefine((data, ctx) => {
    if (data.approver_type === 'role' && !data.approver_role) {
      ctx.addIssue({
        code: z.ZodIssueCode.custom,
        message: 'Approver role is required when type is Role',
        path: ['approver_role'],
      });
    }
    if (data.approver_type === 'user' && !data.approver_user_id) {
      ctx.addIssue({
        code: z.ZodIssueCode.custom,
        message: 'Approver user is required when type is User',
        path: ['approver_user_id'],
      });
    }
  });

export type WorkflowLevelFormData = z.infer<typeof WorkflowLevelSchema>;

// ─── Create workflow schema ───────────────────────────────────────────────────

export const CreateWorkflowSchema = z.object({
  name: z
    .string({ required_error: 'Workflow name is required' })
    .min(2, 'Name must be at least 2 characters')
    .max(255, 'Name must be less than 255 characters')
    .trim(),
  document_type: z.enum(DOCUMENT_TYPES, {
    required_error: 'Document type is required',
    invalid_type_error: 'Please select a valid document type',
  }),
  department_id: z
    .string()
    .uuid('Please select a valid department')
    .nullable()
    .optional(),
  levels: z
    .array(WorkflowLevelSchema)
    .min(1, 'At least one approval level is required')
    .max(10, 'A workflow can have at most 10 levels'),
});

export type CreateWorkflowFormData = z.infer<typeof CreateWorkflowSchema>;

// ─── Update workflow schema ───────────────────────────────────────────────────

export const UpdateWorkflowSchema = CreateWorkflowSchema.partial();

export type UpdateWorkflowFormData = z.infer<typeof UpdateWorkflowSchema>;
