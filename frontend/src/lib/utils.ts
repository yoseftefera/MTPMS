import { clsx, type ClassValue } from "clsx"
import { twMerge } from "tailwind-merge"

export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs))
}

/**
 * Format a monetary string value using Intl.NumberFormat.
 *
 * @param value  – decimal string from the API, e.g. "1000.00"
 * @param currency – ISO 4217 currency code, defaults to "USD"
 * @param locale   – BCP 47 locale string, defaults to "en-US"
 */
export function formatCurrency(
  value: string | number,
  currency = "USD",
  locale = "en-US",
): string {
  const numeric = typeof value === "string" ? parseFloat(value) : value
  if (isNaN(numeric)) return "—"
  return new Intl.NumberFormat(locale, {
    style: "currency",
    currency,
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(numeric)
}

/**
 * Format a percentage string value.
 * @param value – decimal string or number, e.g. "75.50"
 */
export function formatPercent(value: string | number): string {
  const numeric = typeof value === "string" ? parseFloat(value) : value
  if (isNaN(numeric)) return "—"
  return `${numeric.toFixed(1)}%`
}
