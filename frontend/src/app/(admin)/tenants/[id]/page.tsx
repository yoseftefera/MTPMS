"use client";

/**
 * Tenant Detail Page — System_Admin.
 *
 * Shows full details for a single tenant including status badge and
 * suspend/reactivate action buttons.
 *
 * Routes: /admin/tenants/[id]
 *
 * Validates: Requirements 1.5, 1.6, 1.10
 */

import { useState } from "react";
import { useParams } from "next/navigation";
import {
  ArrowLeft,
  Building2,
  Globe,
  Hash,
  Mail,
  Settings2,
  Calendar,
  PauseCircle,
  PlayCircle,
  RefreshCw,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { DetailPageSkeleton } from "@/components/ui/DetailPageSkeleton";
import { SectionErrorBoundary } from "@/components/ui/SectionErrorBoundary";
import { TenantStatusBadge } from "@/components/tenants/TenantStatusBadge";
import { SuspendTenantDialog } from "@/components/tenants/SuspendTenantDialog";
import { ReactivateTenantDialog } from "@/components/tenants/ReactivateTenantDialog";
import { useTenant } from "@/hooks/useTenants";
import type { Tenant } from "@/types/models.types";

// ─── Detail field helper ───────────────────────────────────────────────────────

function DetailField({
  label,
  value,
}: {
  label: string;
  value: React.ReactNode;
}) {
  return (
    <div className="space-y-0.5">
      <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
        {label}
      </p>
      <p className="text-sm text-foreground">{value}</p>
    </div>
  );
}

// ─── Main detail content ──────────────────────────────────────────────────────

function TenantDetailContent({ id }: { id: string }) {
  const { data, isLoading, isError, refetch } = useTenant(id);
  const [suspendOpen, setSuspendOpen] = useState(false);
  const [reactivateOpen, setReactivateOpen] = useState(false);

  if (isLoading) {
    return <DetailPageSkeleton mainLines={6} sidebarCards={2} />;
  }

  if (isError || !data?.data) {
    return (
      <div className="flex flex-col items-center gap-4 py-16 text-center text-muted-foreground">
        <p>Failed to load tenant details.</p>
        <Button variant="outline" size="sm" onClick={() => refetch()}>
          <RefreshCw className="size-4" />
          Retry
        </Button>
      </div>
    );
  }

  const tenant: Tenant = data.data;

  const formatDate = (iso: string) =>
    new Intl.DateTimeFormat("en-US", {
      month: "long",
      day: "numeric",
      year: "numeric",
      hour: "2-digit",
      minute: "2-digit",
    }).format(new Date(iso));

  return (
    <div className="space-y-6">
      {/* Page header */}
      <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div className="space-y-2">
          <a
            href="/admin/tenants"
            className="inline-flex items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
            aria-label="Back to tenants list"
          >
            <ArrowLeft className="size-4" />
            Back to Tenants
          </a>
          <div className="flex flex-wrap items-center gap-3">
            <div
              className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary/10"
              aria-hidden="true"
            >
              <Building2 className="size-5 text-primary" />
            </div>
            <div>
              <h1 className="text-xl font-semibold tracking-tight">
                {tenant.name}
              </h1>
              <div className="mt-0.5 flex items-center gap-2">
                <TenantStatusBadge status={tenant.status} />
                <span className="font-mono text-xs text-muted-foreground">
                  {tenant.tenant_code}
                </span>
              </div>
            </div>
          </div>
        </div>

        {/* Action buttons */}
        <div className="flex shrink-0 items-center gap-2">
          {tenant.status === "active" && (
            <Button
              variant="outline"
              size="sm"
              onClick={() => setSuspendOpen(true)}
              className="gap-1.5 text-amber-700 hover:bg-amber-50 hover:text-amber-800 dark:text-amber-400 dark:hover:bg-amber-900/20"
            >
              <PauseCircle className="size-4" />
              Suspend
            </Button>
          )}
          {tenant.status === "suspended" && (
            <Button
              size="sm"
              onClick={() => setReactivateOpen(true)}
              className="gap-1.5 bg-green-600 text-white hover:bg-green-700 dark:bg-green-700 dark:hover:bg-green-600"
            >
              <PlayCircle className="size-4" />
              Reactivate
            </Button>
          )}
        </div>
      </div>

      {/* Content grid */}
      <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
        {/* Main info card */}
        <div className="lg:col-span-2 rounded-xl border border-border bg-card p-6 space-y-6">
          <div className="flex items-center gap-2 border-b border-border pb-4">
            <Building2
              className="size-4 text-muted-foreground"
              aria-hidden="true"
            />
            <h2 className="text-sm font-medium">Organization Details</h2>
          </div>

          <div className="grid grid-cols-1 gap-5 sm:grid-cols-2">
            <DetailField label="Organization Name" value={tenant.name} />
            <DetailField
              label="Tenant Code"
              value={
                <span className="font-mono font-medium">
                  {tenant.tenant_code}
                </span>
              }
            />
            <DetailField
              label="Subdomain"
              value={
                <span className="font-mono">
                  {tenant.subdomain}.platform.com
                </span>
              }
            />
            <DetailField label="Admin Email" value={tenant.admin_email} />
            <DetailField
              label="Status"
              value={<TenantStatusBadge status={tenant.status} />}
            />
            <DetailField
              label="Registered"
              value={formatDate(tenant.created_at)}
            />
            <DetailField
              label="Last Updated"
              value={formatDate(tenant.updated_at)}
            />
          </div>
        </div>

        {/* Sidebar */}
        <div className="space-y-4">
          {/* Quick info card */}
          <div className="rounded-xl border border-border bg-card p-5 space-y-4">
            <h3 className="text-sm font-medium">Quick Info</h3>

            <div className="space-y-3">
              <div className="flex items-center gap-2 text-sm">
                <Globe
                  className="size-4 shrink-0 text-muted-foreground"
                  aria-hidden="true"
                />
                <span className="font-mono text-muted-foreground">
                  {tenant.subdomain}
                </span>
              </div>
              <div className="flex items-center gap-2 text-sm">
                <Hash
                  className="size-4 shrink-0 text-muted-foreground"
                  aria-hidden="true"
                />
                <span className="font-mono font-medium">
                  {tenant.tenant_code}
                </span>
              </div>
              <div className="flex items-center gap-2 text-sm">
                <Mail
                  className="size-4 shrink-0 text-muted-foreground"
                  aria-hidden="true"
                />
                <span className="truncate text-muted-foreground">
                  {tenant.admin_email}
                </span>
              </div>
              <div className="flex items-center gap-2 text-sm">
                <Calendar
                  className="size-4 shrink-0 text-muted-foreground"
                  aria-hidden="true"
                />
                <span className="text-muted-foreground">
                  {new Intl.DateTimeFormat("en-US", {
                    month: "short",
                    day: "numeric",
                    year: "numeric",
                  }).format(new Date(tenant.created_at))}
                </span>
              </div>
            </div>
          </div>

          {/* Settings summary card */}
          {tenant.settings && Object.keys(tenant.settings).length > 0 && (
            <div className="rounded-xl border border-border bg-card p-5 space-y-4">
              <div className="flex items-center gap-2">
                <Settings2
                  className="size-4 text-muted-foreground"
                  aria-hidden="true"
                />
                <h3 className="text-sm font-medium">Tenant Settings</h3>
              </div>
              <div className="space-y-2">
                {Object.entries(tenant.settings)
                  .slice(0, 6)
                  .map(([key, value]) => (
                    <div key={key} className="flex justify-between gap-2 text-sm">
                      <span className="text-muted-foreground capitalize">
                        {key.replace(/_/g, " ")}
                      </span>
                      <span className="font-mono text-xs text-foreground">
                        {String(value)}
                      </span>
                    </div>
                  ))}
              </div>
            </div>
          )}
        </div>
      </div>

      {/* Confirmation modals */}
      {suspendOpen && (
        <SuspendTenantDialog
          tenant={tenant}
          open={suspendOpen}
          onOpenChange={setSuspendOpen}
        />
      )}
      {reactivateOpen && (
        <ReactivateTenantDialog
          tenant={tenant}
          open={reactivateOpen}
          onOpenChange={setReactivateOpen}
        />
      )}
    </div>
  );
}

// ─── Page ─────────────────────────────────────────────────────────────────────

export default function TenantDetailPage() {
  const params = useParams<{ id: string }>();

  return (
    <SectionErrorBoundary title="Tenant detail">
      <TenantDetailContent id={params.id} />
    </SectionErrorBoundary>
  );
}
