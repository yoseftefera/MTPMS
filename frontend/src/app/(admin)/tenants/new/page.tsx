/**
 * Register New Tenant Page — System_Admin.
 *
 * Full-page form for creating a new tenant. Uses React Hook Form + Zod.
 * Auto-derives subdomain and tenant_code from the org name.
 * Redirects to /admin/tenants on success.
 *
 * Routes: /admin/tenants/new
 *
 * Validates: Requirements 1.6, 1.7
 */

import { Suspense } from "react";
import { FormSkeleton } from "@/components/ui/FormSkeleton";
import { RegisterTenantForm } from "@/components/tenants/RegisterTenantForm";

export default function RegisterTenantPage() {
  return (
    <Suspense fallback={<FormSkeleton />}>
      <RegisterTenantForm />
    </Suspense>
  );
}
