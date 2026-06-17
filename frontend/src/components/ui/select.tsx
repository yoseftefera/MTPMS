"use client"

import * as React from "react"
import { ChevronDown, Check } from "lucide-react"
import { cn } from "@/lib/utils"

// ─── Context ──────────────────────────────────────────────────────────────────

interface SelectContextValue {
  value: string
  onValueChange: (value: string) => void
  open: boolean
  setOpen: (open: boolean) => void
}

const SelectContext = React.createContext<SelectContextValue>({
  value: "",
  onValueChange: () => {},
  open: false,
  setOpen: () => {},
})

// ─── Root ─────────────────────────────────────────────────────────────────────

interface SelectProps {
  value?: string
  defaultValue?: string
  onValueChange?: (value: string) => void
  disabled?: boolean
  children: React.ReactNode
}

function Select({
  value: controlledValue,
  defaultValue = "",
  onValueChange,
  children,
}: SelectProps) {
  const [internalValue, setInternalValue] = React.useState(defaultValue)
  const [open, setOpen] = React.useState(false)

  const value = controlledValue !== undefined ? controlledValue : internalValue

  const handleValueChange = React.useCallback(
    (newValue: string) => {
      if (controlledValue === undefined) setInternalValue(newValue)
      onValueChange?.(newValue)
      setOpen(false)
    },
    [controlledValue, onValueChange],
  )

  return (
    <SelectContext.Provider value={{ value, onValueChange: handleValueChange, open, setOpen }}>
      {children}
    </SelectContext.Provider>
  )
}

// ─── Trigger ─────────────────────────────────────────────────────────────────

function SelectTrigger({
  className,
  children,
  ...props
}: React.ComponentProps<"button">) {
  const { open, setOpen } = React.useContext(SelectContext)

  return (
    <button
      type="button"
      data-slot="select-trigger"
      aria-expanded={open}
      aria-haspopup="listbox"
      onClick={() => setOpen(!open)}
      className={cn(
        "flex h-8 w-full items-center justify-between gap-2 rounded-lg border border-input bg-background px-3 py-1 text-sm shadow-xs",
        "outline-none transition-[color,box-shadow]",
        "focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50",
        "disabled:pointer-events-none disabled:opacity-50",
        "aria-invalid:border-destructive",
        className
      )}
      {...props}
    >
      {children}
      <ChevronDown
        className={cn(
          "size-4 shrink-0 text-muted-foreground transition-transform duration-200",
          open && "rotate-180",
        )}
      />
    </button>
  )
}

// ─── Value ────────────────────────────────────────────────────────────────────

function SelectValue({ placeholder }: { placeholder?: string }) {
  const { value } = React.useContext(SelectContext)
  return (
    <span className={cn("truncate", !value && "text-muted-foreground")}>
      {value || placeholder || "Select…"}
    </span>
  )
}

// ─── Content ──────────────────────────────────────────────────────────────────

function SelectContent({ className, children }: { className?: string; children: React.ReactNode }) {
  const { open, setOpen } = React.useContext(SelectContext)
  const ref = React.useRef<HTMLDivElement>(null)

  // Close on outside click
  React.useEffect(() => {
    if (!open) return
    function handleClick(e: MouseEvent) {
      if (ref.current && !ref.current.contains(e.target as Node)) {
        setOpen(false)
      }
    }
    document.addEventListener("mousedown", handleClick)
    return () => document.removeEventListener("mousedown", handleClick)
  }, [open, setOpen])

  if (!open) return null

  return (
    <div
      ref={ref}
      data-slot="select-content"
      role="listbox"
      className={cn(
        "absolute z-50 mt-1 max-h-60 w-full overflow-auto rounded-lg border border-border bg-popover p-1 text-popover-foreground shadow-md",
        "animate-in fade-in-0 zoom-in-95",
        className,
      )}
    >
      {children}
    </div>
  )
}

// ─── Item ─────────────────────────────────────────────────────────────────────

interface SelectItemProps {
  value: string
  children: React.ReactNode
  className?: string
  disabled?: boolean
}

function SelectItem({ value, children, className, disabled }: SelectItemProps) {
  const { value: selectedValue, onValueChange } = React.useContext(SelectContext)
  const isSelected = selectedValue === value

  return (
    <div
      role="option"
      aria-selected={isSelected}
      data-slot="select-item"
      onClick={() => !disabled && onValueChange(value)}
      className={cn(
        "relative flex w-full cursor-pointer select-none items-center rounded-md py-1.5 pl-8 pr-2 text-sm",
        "outline-none transition-colors",
        "hover:bg-accent hover:text-accent-foreground",
        "focus:bg-accent focus:text-accent-foreground",
        isSelected && "bg-accent/50 font-medium",
        disabled && "pointer-events-none opacity-50",
        className,
      )}
    >
      {isSelected && (
        <Check className="absolute left-2 size-4 text-primary" />
      )}
      {children}
    </div>
  )
}

// ─── Group + Label ────────────────────────────────────────────────────────────

function SelectGroup({ children }: { children: React.ReactNode }) {
  return <div role="group" data-slot="select-group">{children}</div>
}

function SelectLabel({ className, children }: { className?: string; children: React.ReactNode }) {
  return (
    <div
      data-slot="select-label"
      className={cn("px-2 py-1.5 text-xs font-semibold text-muted-foreground", className)}
    >
      {children}
    </div>
  )
}

// ─── Separator ────────────────────────────────────────────────────────────────

function SelectSeparator({ className }: { className?: string }) {
  return (
    <div
      data-slot="select-separator"
      className={cn("-mx-1 my-1 h-px bg-border", className)}
    />
  )
}

export {
  Select,
  SelectTrigger,
  SelectValue,
  SelectContent,
  SelectItem,
  SelectGroup,
  SelectLabel,
  SelectSeparator,
}
