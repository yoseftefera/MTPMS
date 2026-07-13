"use client";

/**
 * PageErrorBoundary — React class-based Error Boundary.
 *
 * Catches rendering errors from any child component tree and displays
 * an accessible error card with a "Try again" retry action.
 *
 * Props:
 *   children   — the subtree to protect
 *   fallback   — optional custom fallback UI (overrides the default card)
 *   onReset    — optional callback invoked on retry; defaults to window.location.reload()
 *
 * Accessibility:
 *   - Fallback container has role="alert" so screen readers announce the error
 *   - Retry button is keyboard-focusable and labelled
 *
 * Validates: Requirements 22.5, 22.7
 */

import React, { Component, type ReactNode } from "react";
import { AlertTriangle, RefreshCw } from "lucide-react";
import { Button } from "@/components/ui/button";

// ─── Types ────────────────────────────────────────────────────────────────────

interface Props {
  children: ReactNode;
  /** Fully custom fallback — rendered instead of the default error card */
  fallback?: ReactNode;
  /** Called when the user clicks "Try again". Defaults to page reload. */
  onReset?: () => void;
}

interface State {
  hasError: boolean;
  error: Error | null;
}

// ─── Component ────────────────────────────────────────────────────────────────

export class PageErrorBoundary extends Component<Props, State> {
  constructor(props: Props) {
    super(props);
    this.state = { hasError: false, error: null };
    this.handleReset = this.handleReset.bind(this);
  }

  static getDerivedStateFromError(error: Error): State {
    return { hasError: true, error };
  }

  componentDidCatch(error: Error, info: React.ErrorInfo) {
    // Log to console in development; swap for a real error-reporting service in prod
    if (process.env.NODE_ENV !== "production") {
      console.error("[PageErrorBoundary] Caught rendering error:", error, info);
    }
  }

  handleReset() {
    if (this.props.onReset) {
      this.props.onReset();
    } else {
      window.location.reload();
    }
    this.setState({ hasError: false, error: null });
  }

  render() {
    if (!this.state.hasError) {
      return this.props.children;
    }

    // Custom fallback takes full control
    if (this.props.fallback) {
      return this.props.fallback;
    }

    // Default error card
    return (
      <div
        role="alert"
        aria-live="assertive"
        aria-atomic="true"
        className="flex min-h-[200px] items-center justify-center p-6"
      >
        <div className="w-full max-w-md rounded-xl border border-destructive/30 bg-destructive/5 p-6 text-center">
          <div className="mx-auto mb-4 flex size-12 items-center justify-center rounded-full bg-destructive/10">
            <AlertTriangle
              className="size-6 text-destructive"
              aria-hidden="true"
            />
          </div>

          <h2 className="text-base font-semibold text-foreground">
            Something went wrong
          </h2>

          <p className="mt-1.5 text-sm text-muted-foreground">
            An unexpected error occurred while rendering this section. Your data
            is safe — this is a display issue only.
          </p>

          {process.env.NODE_ENV !== "production" && this.state.error && (
            <pre className="mt-4 max-h-32 overflow-auto rounded-md bg-muted px-3 py-2 text-left text-xs text-muted-foreground">
              {this.state.error.message}
            </pre>
          )}

          <Button
            variant="outline"
            size="sm"
            onClick={this.handleReset}
            aria-label="Retry loading this section"
            className="mt-5 gap-1.5"
          >
            <RefreshCw className="size-3.5" aria-hidden="true" />
            Try again
          </Button>
        </div>
      </div>
    );
  }
}
