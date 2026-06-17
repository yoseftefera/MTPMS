/**
 * Contract domain types for the Procurement Management Platform.
 * Mirrors the backend Contract API resource shapes.
 *
 * Validates: Requirements 11.1, 11.5, 22.6
 */

import type { ContractStatus } from './models.types';

// ─── Re-export ────────────────────────────────────────────────────────────────

export type { ContractStatus };

// ─── Contract amendment ───────────────────────────────────────────────────────

export interface ContractAmendment {
  id: string;
  amendment_number: number;
  reason: string;
  changes: Record<string, unknown> | null;
  amended_by: {
    id: string;
    name: string;
  };
  created_at: string;
}

// ─── Contract document ────────────────────────────────────────────────────────

export interface ContractDocument {
  id: string;
  document_type: string;
  type_label: string;
  file_name: string;
  file_path: string;
}

// ─── Contract detail (full API response shape) ────────────────────────────────

export interface ContractDetail {
  id: string;
  contract_number: string;
  title: string;
  scope: string;
  total_value: string;
  consumed_value: string;
  consumption_percentage: number;
  currency: string;
  start_date: string;
  end_date: string;
  payment_terms: string | null;
  status: ContractStatus;
  supplier: {
    id: string;
    organization_name: string;
    contact_email: string;
  };
  creator: {
    id: string;
    name: string;
  };
  amendments: ContractAmendment[];
  documents: ContractDocument[];
  purchase_order: {
    id: string;
    po_number: string;
  } | null;
  tender: {
    id: string;
    title: string;
  } | null;
  created_at: string;
  updated_at: string;
}

// ─── Filters ──────────────────────────────────────────────────────────────────

export type ContractFilterStatus = '' | 'draft' | 'active' | 'terminated' | 'expired';

export interface ContractFilters {
  page?: number;
  per_page?: number;
  status?: ContractFilterStatus;
  supplier_id?: string;
  date_from?: string;
  date_to?: string;
}

// ─── Create contract payload ──────────────────────────────────────────────────

export interface CreateContractData {
  supplier_id: string;
  purchase_order_id?: string | null;
  title: string;
  scope: string;
  total_value: number;
  currency: string;
  start_date: string;
  end_date: string;
  payment_terms?: string | null;
}

// ─── Amend contract payload ───────────────────────────────────────────────────

export interface AmendContractData {
  reason: string;
  title?: string;
  scope?: string;
  total_value?: number;
  end_date?: string;
  payment_terms?: string | null;
}

// ─── Terminate contract payload ───────────────────────────────────────────────

export interface TerminateContractData {
  reason: string;
}
