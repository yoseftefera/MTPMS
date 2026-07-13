"use client"

/**
 * Register Tenant Form.
 *
 * Full-page form for registering a new tenant on the platform.
 * Uses React Hook Form + Zod for validation.
 *
 * Features:
 * - Auto-generates subdomain from tenant name (slugified, editable)
 * - Auto-generates tenant_code from tenant name (uppercased abbreviation, editable)
 * - Client-side Zod validation
 * - Server-side error display
 * - Redirects to /admin/tenants on success
 *
 * Validates: Requirements 1.6, 1.7, 22.6, 22.7
 */

import { useEffect } from "react"
import { useRouter } from "next/navigation"
import { useForm } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"
import { ArrowLeft, Building2 } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Alert, AlertDescription } from "@/components/ui/alert"
import { registerTenantSchema, type RegisterTenantFormData } from "@/lib/validations/tenants"
import { useRegisterTenant } from "@/hooks/useTenants"

// ─── Helpers ──────────────────────────────────────────────────────────────────

/** Convert an org name to a URL-safe subdomain slug. */
function nameToSubdomain(name: string): string {
  return name
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9\s-]/g, "")
    .replace(/\s+/g, "-")
    .replace(/-+/g, "-")
    .replace(/^-|-$/g, "")
    .slice(0, 100)
}

/** Convert an org name to an uppercase tenant code (first letters of words, max 10 chars). */
function nameToTenantCode(name: string): string {
  return name
    .trim()
    .split(/\s+/)
    .map((word) => word[0] ?? "")
    .join("")
    .toUpperCase()
    .replace(/[^A-Z0-9]/g, "")
    .slice(0, 10)
}

// ─── Form field component ─────────────────────────────────────────────────────

function FormField({
  id,
  label,
  hint,
  error,
  children,
}: {
  id: string
  label: string
  hint?: string
  error?: string
  children: React.ReactNode
}) {
  return (
    <div className="space-y-1.5">
      <Label htmlFor={id}>{label}</Label>
      {children}
      {hint && !error && (
        <p className="text-xs text-muted-foreground">{hint}</p>
      )}
      {error && (
        <p className="text-xs text-destructive" role="alert">
          {error}
        </p>
      )}
    </div>
  )
}

// ─── Main component ───────────────────────────────────────────────────────────

export function RegisterTenantForm() {
  const router = useRouter()
  const registerTenant = useRegisterTenant()

  const form = useForm<RegisterTenantFormData>({
    resolver: zodResolver(registerTenantSchema),
    defaultValues: {
      name: "",
      subdomain: "",
      admin_email: "",
      tenant_code: "",
    },
  })

  const { watch, setValue } = form
  const nameValue = watch("name")

  // Auto-derive subdomain and tenant_code from name
  useEffect(() => {
    if (nameValue) {
      const currentSubdomain = form.getValues("subdomain")
      const currentCode = form.getValues("tenant_code")

      // Only auto-fill if the user hasn't manually edited these fields
      const autoSubdomain = nameToSubdomain(nameValue)
      const autoCode = nameToTenantCode(nameValue)

      // Replace if it looks like it was auto-generated (matches previous auto value)
      if (!currentSubdomain || currentSubdomain === nameToSubdomain(nameValue.slice(0, -1))) {
        setValue("subdomain", autoSubdomain, { shouldValidate: false })
      }
      if (!currentCode || currentCode === nameToTenantCode(nameValue.slice(0, -1))) {
        setValue("tenant_code", autoCode, { shouldValidate: false })
      }
    }
  }, [nameValue, setValue, form])

  const handleSubmit = form.handleSubmit(async (data) => {
    await registerTenant.mutateAsync(data, {
      onSuccess: () => {
        router.push("/admin/tenants")
      },
    })
  })

  const serverError = registerTenant.error as
    | { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }
    | null

  const { formState: { errors } } = form

  return (
    <div className="mx-auto max-w-xl space-y-8">
      {/* Back link */}
      <a
        href="/admin/tenants"
        className="inline-flex items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
        aria-label="Back to tenants list"
      >
        <ArrowLeft className="size-4" />
        Back to Tenants
      </a>

      {/* Header */}
      <div className="flex items-start gap-4">
        <div className="flex size-12 shrink-0 items-center justify-center rounded-xl bg-primary/10">
          <Building2 className="size-6 text-primary" />
        </div>
        <div>
          <h1 className="text-2xl font-semibold tracking-tight">Register New Tenant</h1>
          <p className="mt-1 text-sm text-muted-foreground">
            Add a new organization to the platform. Default roles, permissions,
            and configuration will be provisioned automatically.
          </p>
        </div>
      </div>

      {/* Server error */}
      {serverError?.response?.data?.message && (
        <Alert variant="destructive">
          <AlertDescription>
            {serverError.response.data.message}
            {serverError.response.data.errors && (
              <ul className="mt-1 list-disc pl-4 text-xs">
                {Object.entries(serverError.response.data.errors).map(([field, msgs]) =>
                  msgs.map((msg, i) => (
                    <li key={`${field}-${i}`}>{msg}</li>
                  )),
                )}
              </ul>
            )}
          </AlertDescription>
        </Alert>
      )}

      {/* Form */}
      <form
        id="register-tenant-form"
        onSubmit={handleSubmit}
        noValidate
        className="rounded-xl border border-border bg-card p-6 space-y-5"
      >
        <FormField
          id="name"
          label="Organization Name"
          hint="The full legal name of the organization."
          error={errors.name?.message}
        >
          <Input
            id="name"
            placeholder="Acme Corporation"
            aria-required="true"
            aria-invalid={!!errors.name}
            {...form.register("name")}
          />
        </FormField>

        <FormField
          id="subdomain"
          label="Subdomain"
          hint="Used for tenant identification (e.g. acme.platform.com). Lowercase letters, numbers, and hyphens only."
          error={errors.subdomain?.message}
        >
          <div className="flex items-center gap-0">
            <Input
              id="subdomain"
              placeholder="acme"
              className="rounded-r-none font-mono lowercase"
              aria-required="true"
              aria-invalid={!!errors.subdomain}
              {...form.register("subdomain")}
            />
            <span className="inline-flex h-10 items-center rounded-r-md border border-l-0 border-input bg-muted px-3 text-sm text-muted-foreground whitespace-nowrap">
              .platform.com
            </span>
          </div>
        </FormField>

        <FormField
          id="admin_email"
          label="Administrator Email"
          hint="The primary contact for this tenant. They will receive admin access."
          error={errors.admin_email?.message}
        >
          <Input
            id="admin_email"
            type="email"
            placeholder="admin@acme.com"
            aria-required="true"
            aria-invalid={!!errors.admin_email}
            {...form.register("admin_email")}
          />
        </FormField>

        <FormField
          id="tenant_code"
          label="Tenant Code"
          hint="Short uppercase identifier used in PR/PO numbers (e.g. ACM). 2–10 uppercase letters and digits."
          error={errors.tenant_code?.message}
        >
          <Input
            id="tenant_code"
            placeholder="ACM"
            className="font-mono uppercase max-w-32"
            aria-required="true"
            aria-invalid={!!errors.tenant_code}
            {...form.register("tenant_code", {
              setValueAs: (v: string) => v.toUpperCase(),
            })}
          />
        </FormField>
      </form>

      {/* Actions */}
      <div className="flex items-center justify-end gap-3">
        <a href="/admin/tenants">
          <Button variant="outline" disabled={registerTenant.isPending}>
            Cancel
          </Button>
        </a>
        <Button
          type="submit"
          form="register-tenant-form"
          disabled={registerTenant.isPending}
        >
          {registerTenant.isPending ? "Registering…" : "Register Tenant"}
        </Button>
      </div>
    </div>
  )
}
