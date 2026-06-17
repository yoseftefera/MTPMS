"use client"

/**
 * Edit User Dialog.
 *
 * Opens a modal form pre-populated with the user's current data for editing.
 * Uses React Hook Form + Zod validation.
 *
 * Validates: Requirements 4.1, 22.6, 22.7
 */

import { useEffect } from "react"
import { useForm } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"
import { Button } from "@/components/ui/button"
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from "@/components/ui/dialog"
import { Alert, AlertDescription } from "@/components/ui/alert"
import { UserFormFields } from "./UserFormFields"
import { editUserSchema, type EditUserFormData, type TenantRole } from "@/lib/validations/users"
import { useUpdateUser } from "@/hooks/useUsers"
import type { User } from "@/types/models.types"

interface EditUserDialogProps {
  user: User
  open: boolean
  onOpenChange: (open: boolean) => void
  onSuccess?: () => void
}

export function EditUserDialog({
  user,
  open,
  onOpenChange,
  onSuccess,
}: EditUserDialogProps) {
  const updateUser = useUpdateUser(user.id)

  const form = useForm<EditUserFormData>({
    resolver: zodResolver(editUserSchema),
    defaultValues: {
      name: user.name,
      email: user.email,
      role: (user.roles?.[0] as TenantRole) ?? undefined,
      department_id: user.department_id ?? null,
      phone: user.phone ?? null,
    },
  })

  // Sync form values when the user prop changes (e.g. after navigating to a different user)
  useEffect(() => {
    form.reset({
      name: user.name,
      email: user.email,
      role: (user.roles?.[0] as TenantRole) ?? undefined,
      department_id: user.department_id ?? null,
      phone: user.phone ?? null,
    })
  }, [user, form])

  const handleSubmit = form.handleSubmit(async (data) => {
    await updateUser.mutateAsync(
      {
        name: data.name,
        email: data.email,
        role: data.role,
        department_id: data.department_id ?? null,
        phone: data.phone ?? null,
      },
      {
        onSuccess: () => {
          onOpenChange(false)
          onSuccess?.()
        },
      },
    )
  })

  const serverError = updateUser.error as
    | { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }
    | null

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Edit User</DialogTitle>
          <DialogDescription>
            Update the details for <strong>{user.name}</strong>.
          </DialogDescription>
        </DialogHeader>

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

        <form id="edit-user-form" onSubmit={handleSubmit} noValidate>
          <UserFormFields form={form} />
        </form>

        <DialogFooter>
          <Button
            variant="outline"
            onClick={() => onOpenChange(false)}
            disabled={updateUser.isPending}
          >
            Cancel
          </Button>
          <Button
            type="submit"
            form="edit-user-form"
            disabled={updateUser.isPending}
          >
            {updateUser.isPending ? "Saving…" : "Save Changes"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
