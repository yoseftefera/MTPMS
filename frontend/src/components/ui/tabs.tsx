"use client"

import * as React from "react"
import { cn } from "@/lib/utils"

// ─── Context ──────────────────────────────────────────────────────────────────

interface TabsContextValue {
  value: string
  onValueChange: (value: string) => void
}

const TabsContext = React.createContext<TabsContextValue>({
  value: "",
  onValueChange: () => {},
})

// ─── Root ─────────────────────────────────────────────────────────────────────

interface TabsProps extends React.ComponentProps<"div"> {
  value?: string
  defaultValue?: string
  onValueChange?: (value: string) => void
}

function Tabs({
  value: controlledValue,
  defaultValue = "",
  onValueChange,
  className,
  children,
  ...props
}: TabsProps) {
  const [internalValue, setInternalValue] = React.useState(defaultValue)
  const isControlled = controlledValue !== undefined
  const value = isControlled ? controlledValue : internalValue

  const handleChange = React.useCallback(
    (newValue: string) => {
      if (!isControlled) setInternalValue(newValue)
      onValueChange?.(newValue)
    },
    [isControlled, onValueChange],
  )

  return (
    <TabsContext.Provider value={{ value, onValueChange: handleChange }}>
      <div data-slot="tabs" className={cn("flex flex-col gap-2", className)} {...props}>
        {children}
      </div>
    </TabsContext.Provider>
  )
}

// ─── List ─────────────────────────────────────────────────────────────────────

function TabsList({ className, ...props }: React.ComponentProps<"div">) {
  return (
    <div
      role="tablist"
      data-slot="tabs-list"
      className={cn(
        "inline-flex h-9 items-center gap-0.5 rounded-lg border border-border bg-muted p-1 text-muted-foreground",
        className,
      )}
      {...props}
    />
  )
}

// ─── Trigger ─────────────────────────────────────────────────────────────────

interface TabsTriggerProps extends React.ComponentProps<"button"> {
  value: string
}

function TabsTrigger({ value, className, children, ...props }: TabsTriggerProps) {
  const { value: selectedValue, onValueChange } = React.useContext(TabsContext)
  const isSelected = selectedValue === value

  return (
    <button
      role="tab"
      type="button"
      data-slot="tabs-trigger"
      aria-selected={isSelected}
      tabIndex={isSelected ? 0 : -1}
      onClick={() => onValueChange(value)}
      className={cn(
        "inline-flex items-center justify-center whitespace-nowrap rounded-md px-3 py-1.5 text-sm font-medium transition-all",
        "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1",
        "disabled:pointer-events-none disabled:opacity-50",
        isSelected
          ? "bg-background text-foreground shadow-sm"
          : "hover:bg-background/60 hover:text-foreground",
        className,
      )}
      {...props}
    >
      {children}
    </button>
  )
}

// ─── Content ─────────────────────────────────────────────────────────────────

interface TabsContentProps extends React.ComponentProps<"div"> {
  value: string
}

function TabsContent({ value, className, children, ...props }: TabsContentProps) {
  const { value: selectedValue } = React.useContext(TabsContext)
  const isSelected = selectedValue === value

  if (!isSelected) return null

  return (
    <div
      role="tabpanel"
      data-slot="tabs-content"
      tabIndex={0}
      className={cn(
        "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1",
        className,
      )}
      {...props}
    >
      {children}
    </div>
  )
}

export { Tabs, TabsList, TabsTrigger, TabsContent }
