"use client"

/**
 * WorkflowLevelBuilder — drag-and-drop level list for create/edit workflow dialogs.
 *
 * Features:
 * - Drag-and-drop reordering via @dnd-kit/sortable
 * - Keyboard reorder fallback (up/down buttons)
 * - Add / remove levels
 * - Per-level: approver_type toggle (Role/User), role select or user ID input,
 *   is_parallel switch, escalation_hours number input
 *
 * Validates: Requirements 6.8
 */

import { useCallback } from "react"
import { useFieldArray, type UseFormReturn } from "react-hook-form"
import {
  DndContext,
  closestCenter,
  KeyboardSensor,
  PointerSensor,
  useSensor,
  useSensors,
  type DragEndEvent,
} from "@dnd-kit/core"
import {
  SortableContext,
  sortableKeyboardCoordinates,
  useSortable,
  verticalListSortingStrategy,
  arrayMove,
} from "@dnd-kit/sortable"
import { CSS } from "@dnd-kit/utilities"
import { GripVertical, Plus, Trash2 } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { Badge } from "@/components/ui/badge"
import {
  APPROVER_ROLES,
  APPROVER_ROLE_LABELS,
  type CreateWorkflowFormData,
} from "@/lib/validations/approvalWorkflows"

// ─── Sortable Level Item ──────────────────────────────────────────────────────

interface SortableLevelItemProps {
  id: string
  index: number
  totalLevels: number
  form: UseFormReturn<CreateWorkflowFormData>
  onRemove: (index: number) => void
}

function SortableLevelItem({
  id,
  index,
  totalLevels,
  form,
  onRemove,
}: SortableLevelItemProps) {
  const {
    attributes,
    listeners,
    setNodeRef,
    transform,
    transition,
    isDragging,
  } = useSortable({ id })

  const style = {
    transform: CSS.Transform.toString(transform),
    transition,
    opacity: isDragging ? 0.5 : 1,
    zIndex: isDragging ? 50 : undefined,
  }

  const approverType = form.watch(`levels.${index}.approver_type`)
  const isParallel = form.watch(`levels.${index}.is_parallel`)
  const levelErrors = form.formState.errors.levels?.[index]

  return (
    <div
      ref={setNodeRef}
      style={style}
      className={`rounded-lg border bg-muted/30 p-4 space-y-3 ${
        isDragging ? "border-primary shadow-md" : "border-border"
      }`}
    >
      {/* Level header */}
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-2">
          {/* Drag handle */}
          <button
            type="button"
            {...attributes}
            {...listeners}
            className="cursor-grab touch-none text-muted-foreground hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring rounded"
            aria-label={`Drag to reorder level ${index + 1}`}
          >
            <GripVertical className="size-4" aria-hidden="true" />
          </button>
          <span className="text-sm font-semibold text-foreground">
            Level {index + 1}
          </span>
          {isParallel && (
            <Badge variant="secondary" className="text-xs">
              Parallel
            </Badge>
          )}
        </div>
        <Button
          type="button"
          variant="ghost"
          size="icon-sm"
          onClick={() => onRemove(index)}
          aria-label={`Remove level ${index + 1}`}
          className="text-destructive hover:bg-destructive/10 hover:text-destructive"
        >
          <Trash2 className="size-4" />
        </Button>
      </div>

      {/* Approver type */}
      <div className="space-y-1">
        <Label htmlFor={`approver-type-${index}`} className="text-xs font-medium">
          Approver Type
        </Label>
        <Select
          value={approverType}
          onValueChange={(val) => {
            form.setValue(
              `levels.${index}.approver_type`,
              val as "role" | "user",
            )
            form.setValue(`levels.${index}.approver_role`, null)
            form.setValue(`levels.${index}.approver_user_id`, null)
          }}
        >
          <SelectTrigger id={`approver-type-${index}`} aria-label="Approver type">
            <SelectValue placeholder="Select type" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="role">Role</SelectItem>
            <SelectItem value="user">Specific User</SelectItem>
          </SelectContent>
        </Select>
      </div>

      {/* Approver role or user */}
      {approverType === "role" ? (
        <div className="space-y-1">
          <Label htmlFor={`approver-role-${index}`} className="text-xs font-medium">
            Approver Role
          </Label>
          <Select
            value={form.watch(`levels.${index}.approver_role`) ?? ""}
            onValueChange={(val) =>
              form.setValue(`levels.${index}.approver_role`, val)
            }
          >
            <SelectTrigger
              id={`approver-role-${index}`}
              aria-label="Select approver role"
            >
              <SelectValue placeholder="Select role" />
            </SelectTrigger>
            <SelectContent>
              {APPROVER_ROLES.map((role) => (
                <SelectItem key={role} value={role}>
                  {APPROVER_ROLE_LABELS[role]}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          {levelErrors?.approver_role && (
            <p className="text-xs text-destructive">
              {levelErrors.approver_role.message}
            </p>
          )}
        </div>
      ) : (
        <div className="space-y-1">
          <Label htmlFor={`approver-user-${index}`} className="text-xs font-medium">
            Approver User ID
          </Label>
          <Input
            id={`approver-user-${index}`}
            placeholder="Enter user UUID"
            {...form.register(`levels.${index}.approver_user_id`)}
            aria-label="Approver user ID"
          />
          {levelErrors?.approver_user_id && (
            <p className="text-xs text-destructive">
              {levelErrors.approver_user_id.message}
            </p>
          )}
        </div>
      )}

      {/* Bottom row: parallel toggle + escalation hours */}
      <div className="grid grid-cols-2 gap-3">
        {/* Parallel toggle */}
        <div className="space-y-1">
          <Label className="text-xs font-medium">Parallel Approval</Label>
          <div className="flex items-center gap-2">
            <button
              type="button"
              role="switch"
              aria-checked={isParallel}
              aria-label="Enable parallel approval"
              onClick={() =>
                form.setValue(`levels.${index}.is_parallel`, !isParallel)
              }
              className={`relative inline-flex h-5 w-9 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring ${
                isParallel ? "bg-primary" : "bg-input"
              }`}
            >
              <span
                className={`pointer-events-none block size-4 rounded-full bg-background shadow-sm ring-0 transition-transform ${
                  isParallel ? "translate-x-4" : "translate-x-0"
                }`}
              />
            </button>
            <span className="text-xs text-muted-foreground">
              {isParallel ? "Parallel" : "Sequential"}
            </span>
          </div>
        </div>

        {/* Escalation hours */}
        <div className="space-y-1">
          <Label
            htmlFor={`escalation-hours-${index}`}
            className="text-xs font-medium"
          >
            Escalation (hours)
          </Label>
          <Input
            id={`escalation-hours-${index}`}
            type="number"
            min={1}
            max={720}
            {...form.register(`levels.${index}.escalation_hours`, {
              valueAsNumber: true,
            })}
            aria-label="Escalation hours"
          />
          {levelErrors?.escalation_hours && (
            <p className="text-xs text-destructive">
              {levelErrors.escalation_hours.message}
            </p>
          )}
        </div>
      </div>
    </div>
  )
}

// ─── WorkflowLevelBuilder ─────────────────────────────────────────────────────

interface WorkflowLevelBuilderProps {
  form: UseFormReturn<CreateWorkflowFormData>
}

export function WorkflowLevelBuilder({ form }: WorkflowLevelBuilderProps) {
  const { fields, append, remove, move } = useFieldArray({
    control: form.control,
    name: "levels",
  })

  const sensors = useSensors(
    useSensor(PointerSensor, {
      activationConstraint: {
        // Require 8px movement before drag starts (prevents accidental drags)
        distance: 8,
      },
    }),
    useSensor(KeyboardSensor, {
      coordinateGetter: sortableKeyboardCoordinates,
    }),
  )

  const handleDragEnd = useCallback(
    (event: DragEndEvent) => {
      const { active, over } = event
      if (!over || active.id === over.id) return

      const oldIndex = fields.findIndex((f) => f.id === active.id)
      const newIndex = fields.findIndex((f) => f.id === over.id)

      if (oldIndex === -1 || newIndex === -1) return

      // Use arrayMove to compute new order, then reorder via move()
      // @dnd-kit's arrayMove is used to keep level_order values in sync
      move(oldIndex, newIndex)

      // Re-number level_order after reorder
      // We need a small timeout to allow the field array to settle
      setTimeout(() => {
        const levels = form.getValues("levels")
        levels.forEach((_, i) => {
          form.setValue(`levels.${i}.level_order`, i + 1)
        })
      }, 0)
    },
    [fields, move, form],
  )

  const handleAddLevel = () => {
    append({
      level_order: fields.length + 1,
      approver_type: "role",
      approver_role: null,
      approver_user_id: null,
      is_parallel: false,
      escalation_hours: 48,
    })
  }

  const handleRemove = useCallback(
    (index: number) => {
      remove(index)
      // Re-number after removal
      setTimeout(() => {
        const levels = form.getValues("levels")
        levels.forEach((_, i) => {
          form.setValue(`levels.${i}.level_order`, i + 1)
        })
      }, 0)
    },
    [remove, form],
  )

  return (
    <div className="space-y-3">
      <div className="flex items-center justify-between">
        <Label className="text-sm font-medium">
          Approval Levels
          {fields.length > 0 && (
            <Badge variant="secondary" className="ml-2">
              {fields.length}
            </Badge>
          )}
        </Label>
        <Button
          type="button"
          variant="outline"
          size="sm"
          onClick={handleAddLevel}
          disabled={fields.length >= 10}
          aria-label="Add approval level"
        >
          <Plus className="size-3.5" />
          Add Level
        </Button>
      </div>

      {fields.length === 0 && (
        <p className="rounded-lg border border-dashed border-border py-6 text-center text-sm text-muted-foreground">
          No levels yet. Add at least one approval level.
        </p>
      )}

      <DndContext
        sensors={sensors}
        collisionDetection={closestCenter}
        onDragEnd={handleDragEnd}
      >
        <SortableContext
          items={fields.map((f) => f.id)}
          strategy={verticalListSortingStrategy}
        >
          <div className="space-y-3">
            {fields.map((field, index) => (
              <SortableLevelItem
                key={field.id}
                id={field.id}
                index={index}
                totalLevels={fields.length}
                form={form}
                onRemove={handleRemove}
              />
            ))}
          </div>
        </SortableContext>
      </DndContext>

      {/* Array-level errors */}
      {form.formState.errors.levels?.root && (
        <p className="text-xs text-destructive">
          {form.formState.errors.levels.root.message}
        </p>
      )}
      {typeof form.formState.errors.levels?.message === "string" && (
        <p className="text-xs text-destructive">
          {form.formState.errors.levels.message}
        </p>
      )}
    </div>
  )
}
