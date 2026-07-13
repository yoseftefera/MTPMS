/**
 * Zod validation schemas for Invoice and Payment forms.
 *
 * Validates: Requirements 14.1, 14.8, 22.7
 */

import { z } from "zod"

export const CURRENCIES = ["USD", "EUR", "GBP", "ETB", "KES", "NGN", "GHS", "ZAR"]

export const PAYMENT_METHODS = [
  { value: "bank_transfer", label: "Bank Transfer" },
  { value: "cheque",        label: "Cheque"         },
  { value: "cash",          label: "Cash"           },
  { value: "mobile_money",  label: "Mobile Money"   },
  { value: "credit_card",   label: "Credit Card"    },
] as const

// ─── Create invoice ───────────────────────────────────────────────────────────

export const createInvoiceSchema = z.object({
  purchase_order_id: z.string().optional().nullable(),
  contract_id: z.string().optional().nullable(),
  invoice_date: z
    .string({ required_error: "Invoice date is required" })
    .min(1, "Invoice date is required"),
  due_date: z
    .string({ required_error: "Due date is required" })
    .min(1, "Due date is required"),
  currency: z.string().length(3, "Currency must be a 3-letter ISO code").default("USD"),
  total_amount: z
    .string({ required_error: "Total amount is required" })
    .refine((v) => {
      const n = parseFloat(v)
      return !isNaN(n) && n > 0
    }, "Total amount must be a positive number"),
  notes: z.string().max(2000, "Notes must be under 2000 characters").optional().nullable(),
})

export type CreateInvoiceFormData = z.infer<typeof createInvoiceSchema>

// ─── Reject invoice (reason dialog) ──────────────────────────────────────────

export const rejectInvoiceSchema = z.object({
  reason: z
    .string({ required_error: "Reason is required" })
    .min(10, "Reason must be at least 10 characters")
    .max(2000, "Reason must be under 2000 characters"),
})

export type RejectInvoiceFormData = z.infer<typeof rejectInvoiceSchema>

// ─── Record payment ───────────────────────────────────────────────────────────

export const recordPaymentSchema = z.object({
  amount_paid: z
    .string({ required_error: "Amount is required" })
    .refine((v) => {
      const n = parseFloat(v)
      return !isNaN(n) && n > 0
    }, "Amount must be a positive number"),
  payment_method: z.enum(
    ["bank_transfer", "cheque", "cash", "mobile_money", "credit_card"],
    { required_error: "Payment method is required" },
  ),
  payment_reference: z
    .string()
    .max(200, "Reference must be under 200 characters")
    .optional()
    .nullable(),
})

export type RecordPaymentFormData = z.infer<typeof recordPaymentSchema>
