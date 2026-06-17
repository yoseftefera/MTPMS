"use client"

/**
 * ContractNavLink — navigation link for the Contract Lifecycle Management module.
 *
 * Visible to: Procurement_Officer, Tenant_Admin
 *
 * Validates: Requirements 11.1, 22.6
 */

import { useAuthStore } from '@/store/authStore';

const CONTRACT_ROLES = ['Procurement_Officer', 'Tenant_Admin'];

export function ContractNavLink() {
  const role = useAuthStore((s) => s.role);

  if (!role || !CONTRACT_ROLES.includes(role)) return null;

  return (
    <a
      href="/contracts"
      className="rounded-md px-3 py-1.5 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
    >
      Contracts
    </a>
  );
}
