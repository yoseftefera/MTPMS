/**
 * Admin layout — System_Admin area.
 *
 * Wraps all System_Admin pages. Provides a dedicated admin top navigation
 * bar separate from the tenant-scoped dashboard layout.
 *
 * Routes:
 *   /admin/tenants               — tenant list
 *   /admin/tenants/new           — register new tenant
 *   /admin/tenants/[id]          — tenant detail
 *   /admin/tenants/analytics     — analytics dashboard
 *
 * Validates: Requirements 1.6, 1.8
 */

import { PageErrorBoundary } from '@/components/ui/PageErrorBoundary';
import { ThemeToggle } from '@/components/ui/ThemeToggle';

export default function AdminLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return (
    <div className="flex min-h-screen flex-col bg-background">
      {/* Skip-to-content (WCAG 2.4.1) */}
      <a
        href="#main-content"
        className="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50 focus:rounded-md focus:bg-background focus:px-4 focus:py-2 focus:text-sm focus:font-medium focus:text-foreground focus:shadow-md focus:outline-none focus:ring-2 focus:ring-ring"
      >
        Skip to content
      </a>

      {/* Admin nav bar */}
      <header
        role="banner"
        className="sticky top-0 z-40 flex h-14 items-center gap-4 border-b border-border bg-card px-4 shadow-xs sm:px-6"
      >
        <a
          href="/admin/tenants"
          className="flex items-center gap-2 shrink-0 rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-ring"
          aria-label="PMP Admin — go to tenant list"
        >
          <span className="text-base font-semibold tracking-tight">PMP</span>
          <span className="rounded-md bg-primary/10 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-primary">
            Admin
          </span>
        </a>

        <nav
          role="navigation"
          aria-label="Admin navigation"
          className="flex flex-1 items-center gap-1 text-sm overflow-x-auto min-w-0"
        >
          <a
            href="/admin/tenants"
            className="rounded-md px-3 py-1.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground whitespace-nowrap focus:outline-none focus-visible:ring-2 focus-visible:ring-ring"
          >
            Tenants
          </a>
          <a
            href="/admin/tenants/analytics"
            className="rounded-md px-3 py-1.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground whitespace-nowrap focus:outline-none focus-visible:ring-2 focus-visible:ring-ring"
          >
            Analytics
          </a>
        </nav>

        <div className="flex items-center gap-1 shrink-0">
          <ThemeToggle />
        </div>
      </header>

      {/* Page content */}
      <main
        id="main-content"
        role="main"
        aria-label="Admin page content"
        className="flex-1 min-w-0 overflow-x-hidden px-4 py-6 sm:px-6"
      >
        <div className="mx-auto w-full max-w-screen-2xl">
          <PageErrorBoundary>{children}</PageErrorBoundary>
        </div>
      </main>
    </div>
  );
}
