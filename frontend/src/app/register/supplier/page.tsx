"use client"

/**
 * Public supplier self-registration form.
 *
 * Accessible at /register/supplier — NO authentication required.
 * This page is intentionally outside the (dashboard) route group so it
 * renders without the auth guard and dashboard navigation.
 *
 * Fields: organization_name, contact_name, contact_email, contact_phone,
 *         business_category
 *
 * On success shows a confirmation state instead of redirecting.
 *
 * Validates: Requirements 7.1, 7.2, 22.6, 22.7
 */

import { useState } from "react"
import { useForm } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"
import { CheckCircle2, Building2, ChevronRight } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Alert, AlertDescription } from "@/components/ui/alert"
import { Card } from "@/components/ui/card"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import {
  supplierRegistrationSchema,
  type SupplierRegistrationFormData,
  BUSINESS_CATEGORIES,
} from "@/lib/validations/suppliers"
import { registerSupplier } from "@/lib/api/suppliers"

// ─── Success confirmation ─────────────────────────────────────────────────────

function SuccessConfirmation({ orgName }: { orgName: string }) {
  return (
    <div className="flex flex-col items-center gap-6 py-8 text-center">
      <div
        className="flex size-16 items-center justify-center rounded-full bg-green-100 dark:bg-green-900/30"
        aria-hidden="true"
      >
        <CheckCircle2 className="size-8 text-green-600 dark:text-green-400" />
      </div>

      <div className="space-y-2">
        <h2 className="text-xl font-semibold">Registration Submitted!</h2>
        <p className="text-sm text-muted-foreground max-w-sm">
          Thank you, <strong>{orgName}</strong>. Your supplier registration has been received
          and is currently under review. Our procurement team will verify your details and
          notify you at your registered email address.
        </p>
      </div>

      <div className="rounded-lg border border-border bg-muted/50 px-6 py-4 text-sm text-muted-foreground max-w-sm">
        <p className="font-medium text-foreground mb-1">What happens next?</p>
        <ol className="space-y-1 text-left list-decimal list-inside">
          <li>Your application enters <em>Pending Verification</em></li>
          <li>Our Procurement Officer reviews your documents</li>
          <li>You receive an email with the outcome</li>
          <li>Once approved, you can log in and start bidding</li>
        </ol>
      </div>

      <a
        href="/"
        className="text-sm text-primary underline-offset-2 hover:underline"
      >
        Return to homepage
      </a>
    </div>
  )
}

// ─── Registration form ────────────────────────────────────────────────────────

export default function SupplierRegistrationPage() {
  const [submitted, setSubmitted] = useState(false)
  const [submittedOrgName, setSubmittedOrgName] = useState("")
  const [serverError, setServerError] = useState<string | null>(null)
  const [isSubmitting, setIsSubmitting] = useState(false)

  const {
    register,
    handleSubmit,
    setValue,
    watch,
    formState: { errors },
  } = useForm<SupplierRegistrationFormData>({
    resolver: zodResolver(supplierRegistrationSchema),
    defaultValues: {
      organization_name: "",
      contact_name: "",
      contact_email: "",
      contact_phone: "",
      business_category: "",
    },
  })

  const selectedCategory = watch("business_category")

  async function onSubmit(data: SupplierRegistrationFormData) {
    setServerError(null)
    setIsSubmitting(true)

    try {
      await registerSupplier({
        organization_name: data.organization_name,
        contact_name: data.contact_name,
        contact_email: data.contact_email,
        contact_phone: data.contact_phone || null,
        business_category: data.business_category,
      })
      setSubmittedOrgName(data.organization_name)
      setSubmitted(true)
    } catch (err: unknown) {
      const axiosError = err as {
        response?: { data?: { message?: string; errors?: Record<string, string[]> } }
      }
      const message =
        axiosError?.response?.data?.message ??
        "Registration failed. Please check your details and try again."
      setServerError(message)
    } finally {
      setIsSubmitting(false)
    }
  }

  return (
    <div className="min-h-screen bg-background">
      {/* Minimal public header */}
      <header className="border-b border-border bg-card px-6 py-4">
        <div className="mx-auto flex max-w-2xl items-center gap-2">
          <Building2 className="size-5 text-primary" aria-hidden="true" />
          <span className="text-base font-semibold tracking-tight">
            Procurement Management Platform
          </span>
        </div>
      </header>

      <main className="mx-auto max-w-2xl px-4 py-10">
        <Card className="p-6 sm:p-8">
          {submitted ? (
            <SuccessConfirmation orgName={submittedOrgName} />
          ) : (
            <>
              {/* Form header */}
              <div className="mb-6 space-y-1">
                <h1 className="text-2xl font-semibold tracking-tight">
                  Supplier Registration
                </h1>
                <p className="text-sm text-muted-foreground">
                  Register as a supplier to receive tender invitations and submit bids.
                  Your application will be reviewed by our procurement team.
                </p>
              </div>

              {/* Breadcrumb hint */}
              <div className="mb-6 flex items-center gap-1 text-xs text-muted-foreground">
                <span>Register</span>
                <ChevronRight className="size-3" aria-hidden="true" />
                <span className="font-medium text-foreground">Supplier Application</span>
              </div>

              <form onSubmit={handleSubmit(onSubmit)} noValidate className="space-y-5">
                {/* Organization name */}
                <div className="space-y-1.5">
                  <Label htmlFor="org-name">
                    Organization Name{" "}
                    <span aria-hidden="true" className="text-destructive">*</span>
                  </Label>
                  <Input
                    id="org-name"
                    placeholder="Acme Supplies Ltd."
                    autoComplete="organization"
                    aria-required="true"
                    aria-describedby={errors.organization_name ? "org-name-error" : undefined}
                    {...register("organization_name")}
                  />
                  {errors.organization_name && (
                    <p
                      id="org-name-error"
                      className="text-sm text-destructive"
                      role="alert"
                    >
                      {errors.organization_name.message}
                    </p>
                  )}
                </div>

                {/* Contact name */}
                <div className="space-y-1.5">
                  <Label htmlFor="contact-name">
                    Contact Person Name{" "}
                    <span aria-hidden="true" className="text-destructive">*</span>
                  </Label>
                  <Input
                    id="contact-name"
                    placeholder="Jane Smith"
                    autoComplete="name"
                    aria-required="true"
                    aria-describedby={errors.contact_name ? "contact-name-error" : undefined}
                    {...register("contact_name")}
                  />
                  {errors.contact_name && (
                    <p
                      id="contact-name-error"
                      className="text-sm text-destructive"
                      role="alert"
                    >
                      {errors.contact_name.message}
                    </p>
                  )}
                </div>

                {/* Contact email */}
                <div className="space-y-1.5">
                  <Label htmlFor="contact-email">
                    Contact Email{" "}
                    <span aria-hidden="true" className="text-destructive">*</span>
                  </Label>
                  <Input
                    id="contact-email"
                    type="email"
                    placeholder="jane@acmesupplies.com"
                    autoComplete="email"
                    aria-required="true"
                    aria-describedby={errors.contact_email ? "contact-email-error" : undefined}
                    {...register("contact_email")}
                  />
                  {errors.contact_email && (
                    <p
                      id="contact-email-error"
                      className="text-sm text-destructive"
                      role="alert"
                    >
                      {errors.contact_email.message}
                    </p>
                  )}
                </div>

                {/* Contact phone */}
                <div className="space-y-1.5">
                  <Label htmlFor="contact-phone">
                    Contact Phone{" "}
                    <span className="text-xs text-muted-foreground">(optional)</span>
                  </Label>
                  <Input
                    id="contact-phone"
                    type="tel"
                    placeholder="+1 555 000 0000"
                    autoComplete="tel"
                    aria-describedby={errors.contact_phone ? "contact-phone-error" : undefined}
                    {...register("contact_phone")}
                  />
                  {errors.contact_phone && (
                    <p
                      id="contact-phone-error"
                      className="text-sm text-destructive"
                      role="alert"
                    >
                      {errors.contact_phone.message}
                    </p>
                  )}
                </div>

                {/* Business category */}
                <div className="space-y-1.5">
                  <Label htmlFor="business-category">
                    Business Category{" "}
                    <span aria-hidden="true" className="text-destructive">*</span>
                  </Label>
                  <Select
                    value={selectedCategory}
                    onValueChange={(val) =>
                      setValue("business_category", val, { shouldValidate: true })
                    }
                  >
                    <SelectTrigger
                      id="business-category"
                      aria-required="true"
                      aria-describedby={
                        errors.business_category ? "business-category-error" : undefined
                      }
                    >
                      <SelectValue placeholder="Select your primary business category…" />
                    </SelectTrigger>
                    <SelectContent>
                      {BUSINESS_CATEGORIES.map((cat) => (
                        <SelectItem key={cat} value={cat}>
                          {cat}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                  {errors.business_category && (
                    <p
                      id="business-category-error"
                      className="text-sm text-destructive"
                      role="alert"
                    >
                      {errors.business_category.message}
                    </p>
                  )}
                </div>

                {/* Server error */}
                {serverError && (
                  <Alert variant="destructive" role="alert">
                    <AlertDescription>{serverError}</AlertDescription>
                  </Alert>
                )}

                {/* Submit */}
                <div className="pt-2">
                  <Button
                    type="submit"
                    className="w-full"
                    disabled={isSubmitting}
                    aria-label="Submit supplier registration"
                  >
                    {isSubmitting ? "Submitting…" : "Submit Registration"}
                  </Button>
                </div>

                <p className="text-center text-xs text-muted-foreground">
                  By submitting this form you agree to our terms of service.
                  You will be notified by email once your registration is reviewed.
                </p>
              </form>
            </>
          )}
        </Card>
      </main>
    </div>
  )
}
