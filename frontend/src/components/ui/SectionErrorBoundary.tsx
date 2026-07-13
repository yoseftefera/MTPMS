"use client";

/**
 * SectionErrorBoundary — lightweight inline error boundary for page sections.
 *
 * Unlike PageErrorBoundary (which centers a full error card), this variant
 * renders a compact inline error strip inside the content flow, making it
 * suitable for wrapping individual table sections, form sections, sidebars,
 * or chart panels without disrupting the overall page layout.
 *
 * Props:
 *   children   — the subtree to protect
 *   title      — short label identifying the section (e.g. "Budget chart")
 *   fallback   — optional fully-custom fallback (overrides the default strip)
 *   onReset    — optional callback invoked on retry; resets error state only
 *                (no page reload — the section will re-render from its parent)
 *
 * Accessibility:
 *   - The fallback strip carries role="alert" so screen readers announce it
 *   - The retry button is focusable and labelled
 *
 * Validates: Requirements 22.5, 22.7
 */

import React, { Component, type ReactNode } from "react";
import { AlertCircle, RefreshCw } from "lucide-react";
import { Button } from "@/components/ui/button";

// ─── Types ────────────────────────────────────────────────────────────────────

interface Props {
  children: ReactNode;
  /** Human-readable label for the protected section — shown in the error strip */
  title?: string;
  /** Fully custom fallback — rendered instead of the default error strip */
  fallback?: ReactNode;
  /** Called when the user clicks "Retry". Resets state; no page reload. */
  onReset?: () => void;
}

interface State {
  hasError: boolean;
  error: Error | null;
}

// ─── Component ────────────────────────────────────────────────────────────────

export class SectionErrorBoundary extends Component<Props, State> {
  constructor(props: Props) {
    super(props);
    this.state = { hasError: false, error: null };
    this.handleReset = this.handleReset.bind(this);
  }

  static getDerivedStateFromError(error: Error): State {
    return { hasError: true, error };
  }

  componentDidCatch(error: Error, info: React.ErrorInfo) {
    if (process.env.NODE_ENV !== "production") {
      console.error(
        `[SectionErrorBoundary${this.props.title ? ` "${this.props.title}"` : ""}] Caught error:`,
        error,
        info,
      );
    }
  }

  handleReset() {
    if (this.props.onReset) {
      this.props.onReset();
    }
    this.setState({ hasError: false, error: null });
  }

  render() {
    if (!this.state.hasError) {
      return this.props.children;
    }

    if (this.props.fallback) {
      return this.props.fallback;
    }

    return (
      <div
        role="alert"
        aria-live="polite"
        className="flex items-center gap-3 rounded-lg border border-destructive/30 bg-destructive/5 px-4 py-3 text-sm"
      >
        <AlertCircle
          className="size-4 shrink-0 text-destructive"
          aria-hidden="true"
        />
        <span className="flex-1 text-muted-foreground">
          {this.props.title
            ? `Failed to render "${this.props.title}".`
            : "This section failed to render."}
          {process.env.NODE_ENV !== "production" && this.state.error && (
            <span className="ml-1 font-mono text-xs">
              ({this.state.error.message})
            </span>
          )}
        </span>
        <Button
          variant="ghost"
          size="sm"
          onClick={this.handleReset}
          aria-label={`Retry${this.props.title ? ` "${this.props.title}"` : " this section"}`}
          className="h-7 shrink-0 gap-1 px-2 text-xs"
        >
          <RefreshCw className="size-3" aria-hidden="true" />
          Retry
        </Button>
      </div>
    );
  }
}
