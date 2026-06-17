"use client"

/**
 * Shared form fields for create/edit user forms.
 *
 * Renders: name, email, role (select), department (optional text), phone (optional).
 * Consumed by both CreateUserDialog and EditUserDialog.
 */

import type { UseFormReturn } from "react-hook-form"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { TENANT_ROLES, ROLE_LABELS, type TenantRole } from "@/lib/validations/users"
import type { CreateUserFormData } from "@/lib/validations/users"

interface UserFormFieldsProps {
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  form: UseFormReturn<any>
}

export function UserFormFields({ form }: UserFormFieldsProps) {
  const {
    register,
    setValue,
    watch,
    formState: { errors },
  } = form

  const selectedRole = watch("role") as TenantRole | undefined

  return (
    <div className="space-y-4">
      {/* Name */}
      <div className="space-y-1.5">
        <Label htmlFor="name">
          Full Name <span className="text-destructive">*</span>
        </Label>
        <Input
          id="name"
          placeholder="e.g. Jane Smith"
          aria-invalid={!!errors.name}
          {...register("name")}
        />
        {errors.name && (
          <p className="text-xs text-destructive" role="alert">
            {errors.name.message as string}
          </p>
        )}
      </div>

      {/* Email */}
      <div className="space-y-1.5">
        <Label htmlFor="email">
          Email Address <span className="text-destructive">*</span>
        </Label>
        <Input
          id="email"
          type="email"
          placeholder="jane@company.com"
          aria-invalid={!!errors.email}
          {...register("email")}
        />
        {errors.email && (
          <p className="text-xs text-destructive" role="alert">
            {errors.email.message as string}
          </p>
        )}
      </div>

      {/* Role */}
      <div className="space-y-1.5">
        <Label htmlFor="role">
          Role <span className="text-destructive">*</span>
        </Label>
        <div className="relative">
          <Select
            value={selectedRole}
            onValueChange={(val) => setValue("role", val, { shouldValidate: true })}
          >
            <SelectTrigger id="role" aria-invalid={!!errors.role}>
              <SelectValue placeholder="Select a role" />
            </SelectTrigger>
            <SelectContent>
              {TENANT_ROLES.map((role) => (
                <SelectItem key={role} value={role}>
                  {ROLE_LABELS[role]}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
        {errors.role && (
          <p className="text-xs text-destructive" role="alert">
            {errors.role.message as string}
          </p>
        )}
      </div>

      {/* Phone (optional) */}
      <div className="space-y-1.5">
        <Label htmlFor="phone">Phone Number</Label>
        <Input
          id="phone"
          type="tel"
          placeholder="+1 555 000 0000"
          {...register("phone")}
        />
        {errors.phone && (
          <p className="text-xs text-destructive" role="alert">
            {errors.phone.message as string}
          </p>
        )}
      </div>
    </div>
  )
}
