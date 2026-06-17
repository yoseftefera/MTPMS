"use client"

/**
 * Create User Dialog.
 *
 * Opens a modal form for creating a new tenant user.
 * Uses React Hook Form + Zod validation.
 * On success, the backend sends a welcome email with a password-setup link.
 *
 * Validates: Requirements 4.1, 4.2, 22.6, 22.7
 */

import { useForm } from "react-hook-form"
import { zodResolver } from "@hookform/resolvers/zod"
import { UserPlus } from "lucide-react"
import { Button } from "@/components/ui/button"
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
  DialogTrigger,
} from "@/components/ui/dialog"
import { Alert, AlertDescription } from "@/components/ui/alert"
import { UserFormFields } from "./UserFormFields"
import { createUserSchema, type CreateUserFormData } from "@/lib/validations/users"
import { useCreateUser } from "@/hooks/useUsers"

interface CreateUserDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  onSuccess?: () => void
}

export function CreateUserDialog({
  open,
  onOpenChange,
  onSuccess,
}: CreateUserDialogProps) {
  const createUser = useCreateUser()

  const form = useForm<CreateUserFormData>({
    resolver: zodResolver(createUserSchema),
    defaultValues: {
      name: "",
      email: "",
      role: undefined,
      department_id: null,
      phone: null,
    },
  })

  const handleSubmit = form.handleSubmit(async (data) => {
    await createUser.mutateAsync(
      {
        name: data.name,
        email: data.email,
        role: data.role,
        department_id: data.department_id ?? null,
        phone: data.phone ?? null,
      },
      {
        onSuccess: () => {
          form.reset()
          onOpenChange(false)
          onSuccess?.()
        },
      },
    )
  })

  // Extract server validation errors from the API response
  const serverError = createUser.error as
    | { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }
    | null

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Create New User</DialogTitle>
          <DialogDescription>
            Fill in the details below. The new user will receive a welcome email
            with a password-setup link valid for 24 hours.
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

        <form id="create-user-form" onSubmit={handleSubmit} noValidate>
          <UserFormFields form={form} />
        </form>

        <DialogFooter>
          <Button
            variant="outline"
            onClick={() => {
              form.reset()
              onOpenChange(false)
            }}
            disabled={createUser.isPending}
          >
            Cancel
          </Button>
          <Button
            type="submit"
            form="create-user-form"
            disabled={createUser.isPending}
          >
            {createUser.isPending ? "Creating…" : "Create User"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}


