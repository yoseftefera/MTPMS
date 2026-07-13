"use client";

/**
 * Framer Motion animation wrapper for Recharts charts.
 *
 * Fades and slides the chart container into view when it enters the viewport.
 * Works for BarChart, LineChart, AreaChart, etc.
 *
 * Validates: Requirements 16.1, 16.10, 22.1
 */

import { useRef } from "react";
import { motion, useInView } from "framer-motion";
import { cn } from "@/lib/utils";

interface AnimatedChartProps {
  children: React.ReactNode;
  className?: string;
  /** Animation delay in seconds */
  delay?: number;
}

export function AnimatedChart({
  children,
  className,
  delay = 0,
}: AnimatedChartProps) {
  const ref = useRef<HTMLDivElement>(null);
  // Trigger animation once when 20% of the element is visible
  const isInView = useInView(ref, { once: true, amount: 0.2 });

  return (
    <motion.div
      ref={ref}
      initial={{ opacity: 0, y: 24 }}
      animate={isInView ? { opacity: 1, y: 0 } : { opacity: 0, y: 24 }}
      transition={{ duration: 0.45, delay, ease: "easeOut" }}
      className={cn("w-full", className)}
    >
      {children}
    </motion.div>
  );
}
