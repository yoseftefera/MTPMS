/**
 * Type definitions for Tender & Bidding Management.
 * Mirrors the backend Eloquent models and API resource shapes.
 *
 * Validates: Requirements 8.1, 8.3, 22.6
 */

import type { Tender, Bid, TenderStatus, BidStatus } from './models.types';

// ─── Re-export from models ────────────────────────────────────────────────────

export type { TenderStatus, BidStatus, Tender, Bid };

// ─── Tender document ──────────────────────────────────────────────────────────

export interface TenderDocument {
  id: string;
  tender_id: string;
  file_path: string;
  file_name: string;
  file_type: string;
  uploaded_by: string;
  created_at: string;
}

// ─── Bid document ─────────────────────────────────────────────────────────────

export interface BidDocument {
  id: string;
  bid_id: string;
  file_path: string;
  file_name: string;
  uploaded_by: string;
  created_at: string;
}

// ─── Tender with relations ────────────────────────────────────────────────────

export interface TenderDetail extends Omit<Tender, 'bids'> {
  currency?: string;
  documents?: TenderDocument[];
  bids?: BidSummary[];
  creator?: {
    id: string;
    name: string;
    email: string;
  };
  bids_count?: number;
}

// ─── Bid summary (for tender detail — officer view) ───────────────────────────

export interface BidSummary {
  id: string;
  tender_id: string;
  supplier_id: string;
  supplier_name?: string;
  supplier?: {
    id: string;
    organization_name: string;
    contact_name: string;
    contact_email: string;
    business_category: string;
  };
  total_amount: string;
  currency: string;
  delivery_days: number;
  technical_notes: string | null;
  status: BidStatus;
  submitted_at: string | null;
  weighted_score: string | null;
  documents?: BidDocument[];
  created_at: string;
  updated_at: string;
}

// ─── Filters ──────────────────────────────────────────────────────────────────

export interface TenderFilters {
  page?: number;
  per_page?: number;
  search?: string;
  status?: TenderStatus | '';
  tender_type?: 'open' | 'restricted' | 'single_source' | '';
  category?: string;
  date_from?: string;
  date_to?: string;
  sort_by?: string;
  sort_dir?: 'asc' | 'desc';
}

// ─── Create / update payloads ─────────────────────────────────────────────────

export interface CreateTenderData {
  title: string;
  description: string;
  category: string;
  tender_type: 'open' | 'restricted' | 'single_source';
  estimated_value: string;
  submission_deadline: string;
  currency?: string;
}

export interface UpdateTenderData extends Partial<CreateTenderData> {}

// ─── Bid payloads ─────────────────────────────────────────────────────────────

export interface SubmitBidData {
  total_amount: string;
  currency: string;
  delivery_days: number;
  technical_notes?: string;
}

export interface UpdateBidData extends Partial<SubmitBidData> {}

// ─── Open tender (supplier-facing) ───────────────────────────────────────────

export interface OpenTender {
  id: string;
  reference_number: string;
  title: string;
  description: string;
  category: string;
  tender_type: 'open' | 'restricted' | 'single_source';
  estimated_value: string;
  currency?: string;
  submission_deadline: string;
  status: TenderStatus;
  published_at: string | null;
  documents?: TenderDocument[];
  created_at: string;
  /** Whether the current supplier has already submitted a bid */
  my_bid?: BidSummary | null;
  bids_count?: number;
}
