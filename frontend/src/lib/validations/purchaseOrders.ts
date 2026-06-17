/**
 * Zod validation schemas for Purchase Order forms.
 *
 * Validates: Requirements 10.2, 10.9, 22.7
 */

import { z } from "zod"

// ─── Constants ────────────────────────────────────────────────────────────────

export const CURRENCIES = ["USD", "EUR", "GBP", "ETB", "KES", "NGN", "GHS", "ZAR"]

// ─── Line item ────────────────────────────────────────────────────────────────

export const poItemSchema = z.object({
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
    .max(50, "UoM must be under 50 characters"),
  unit_price: z
    .string({ required_error: "Unit price is required" })
    .refine((v) => {
      const n = parseFloat(v)
      return !isNaN(n) && n >= 0
    }, "Unit price must be a non-negative number"),
})

export type POItemFormData = z.infer<typeof poItemSchema>

// ─── Create PO ────────────────────────────────────────────────────────────────

export const createPOSchema = z.object({
  supplier_id: z
    .string({ required_error: "Supplier is required" })
    .min(1, "Supplier is required"),
  department_id: z
    .string({ required_error: "Department is required" })
    .min(1, "Department is required"),
  delivery_address: z
    .string({ required_error: "Delivery address is required" })
    .min(1, "Delivery address is required")
    .max(1000, "Delivery address must be under 1000 characters"),
  required_delivery_date: z
    .string({ required_error: "Required delivery date is required" })
    .min(1, "Required delivery date is required"),
  currency: z
    .string()
    .length(3, "Currency must be a 3-letter ISO code")
    .default("USD"),
  notes: z
    .string()
    .max(2000, "Notes must be under 2000 characters")
    .optional(),
  items: z
    .array(poItemSchema)
    .min(1, "At least one line item is required"),
})

export type CreatePOFormData = z.infer<typeof createPOSchema>

// ─── Amend PO ─────────────────────────────────────────────────────────────────

export const amendPOSchema = z.object({
  delivery_address: z
    .string()
    .max(1000, "Delivery address must be under 1000 characters")
    .optional(),
  required_delivery_date: z.string().optional(),
  notes: z
    .string()
    .max(2000, "Notes must be under 2000 characters")
    .optional(),
  items: z.array(poItemSchema).min(1, "At least one line item is required"),
})

export type AmendPOFormData = z.infer<typeof amendPOSchema>

// ─── Reason dialog (reject / cancel) ─────────────────────────────────────────

export const reasonSchema = z.object({
  reason: z
    .string({ required_error: "Reason is required" })
    .min(10, "Reason must be at least 10 characters")
    .max(2000, "Reason must be under 2000 characters"),
})

export type ReasonFormData = z.infer<typeof reasonSchema>
