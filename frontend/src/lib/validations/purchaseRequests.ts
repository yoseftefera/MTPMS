/**
 * Zod validation schemas for Purchase Request forms.
 *
 * Validates: Requirements 5.2, 5.5, 22.7
 */

import { z } from "zod"

// ─── Line item ────────────────────────────────────────────────────────────────

export const prItemSchema = z.object({
  description: z
    .string({ required_error: "Item description is required" })
    .min(1, "Item description is required")
    .max(500, "Description must be under 500 characters"),
  quantity: z
    .string({ required_error: "Quantity is required" })
    .refine((v) => {
      const n = parseFloat(v)
      return !isNaN(n) && n > 0
    }, "Quantity must be a positive number"),
  unit_of_measure: z
    .string({ required_error: "Unit of measure is required" })
    .min(1, "Unit of measure is required")
    .max(50, "Unit of measure must be under 50 characters"),
  estimated_unit_price: z
    .string({ required_error: "Unit price is required" })
    .refine((v) => {
      const n = parseFloat(v)
      return !isNaN(n) && n >= 0
    }, "Unit price must be a non-negative number"),
  budget_code: z.string().max(100, "Budget code must be under 100 characters").optional(),
})

export type PRItemFormData = z.infer<typeof prItemSchema>

// ─── Create PR ────────────────────────────────────────────────────────────────

export const createPRSchema = z.object({
  title: z
    .string({ required_error: "Title is required" })
    .min(1, "Title is required")
    .max(255, "Title must be under 255 characters"),
  department_id: z
    .string({ required_error: "Department is required" })
    .min(1, "Department is required"),
  description: z
    .string()
    .max(2000, "Description must be under 2000 characters")
    .optional(),
  required_date: z.string().optional(),
  currency: z
    .string()
    .length(3, "Currency must be a 3-letter ISO code")
    .default("USD"),
  items: z
    .array(prItemSchema)
    .min(1, "At least one line item is required"),
})

export type CreatePRFormData = z.infer<typeof createPRSchema>
