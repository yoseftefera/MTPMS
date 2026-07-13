"use client";

/**
 * KPI summary card widget used on the dashboard.
 *
 * Shows a metric label, a large value, an optional sub-value / trend,
 * and a Lucide icon. Animates in with Framer Motion.
 *
 * Validates: Requirements 16.1, 22.1
 */

import { motion } from "framer-motion";
import type { LucideIcon } from "lucide-react";
import { cn } from "@/lib/utils";

// ─── Types ────────────────────────────────────────────────────────────────────

type TrendDirection = "up" | "down" | "neutral";

interface KpiCardProps {
  label: string;
  value: string | number;
  subValue?: string;
  trend?: TrendDirection;
  icon: LucideIcon;
  /** Tailwind classes applied to the icon background */
  iconClassName?: string;
  /** If true the card takes a visually prominent alert style */
  alert?: boolean;
  /** Animation stagger delay in seconds */
  delay?: number;
}

// ─── Component ────────────────────────────────────────────────────────────────

export function KpiCard({
  label,
  value,
  subValue,
  trend,
  icon: Icon,
  iconClassName = "bg-primary/10 text-primary",
  alert = false,
  delay = 0,
}: KpiCardProps) {
  const trendColor =
    trend === "up"
      ? "text-green-600 dark:text-green-400"
      : trend === "down"
        ? "text-destructive"
        : "text-muted-foreground";

  return (
    <motion.div
      initial={{ opacity: 0, y: 16 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.35, delay, ease: "easeOut" }}
      className={cn(
        "rounded-xl border bg-card p-5 shadow-xs flex flex-col gap-3",
        alert && "border-destructive/40 bg-destructive/5 dark:bg-destructive/10",
      )}
    >
      <div className="flex items-start justify-between gap-2">
        <p className="text-sm font-medium text-muted-foreground leading-snug">
          {label}
        </p>
        <div
          className={cn(
            "flex size-9 shrink-0 items-center justify-center rounded-lg",
            iconClassName,
          )}
          aria-hidden="true"
        >
          <Icon className="size-4.5" />
        </div>
      </div>

      <div className="flex flex-col gap-0.5">
        <p
          className={cn(
            "text-2xl font-semibold tabular-nums tracking-tight",
            alert && "text-destructive",
          )}
        >
          {value}
        </p>
        {subValue && (
          <p className={cn("text-xs", trendColor)}>{subValue}</p>
        )}
      </div>
    </motion.div>
  );
}
