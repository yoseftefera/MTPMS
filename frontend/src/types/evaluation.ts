/**
 * Type definitions for Bid Evaluation System.
 * Mirrors the backend BidEvaluationCriteria, BidEvaluation, and related
 * API resource shapes.
 *
 * Validates: Requirements 9.1, 9.2, 9.3, 9.4, 9.5
 */

// ─── Criteria ─────────────────────────────────────────────────────────────────

export interface EvaluationCriteria {
  id: string;
  tender_id: string;
  name: string;
  weight: number; // 0–100
  description?: string | null;
  max_score: number; // default 100
  created_at: string;
  updated_at: string;
}

// ─── Score ────────────────────────────────────────────────────────────────────

export interface BidEvaluationScore {
  id: string;
  bid_id: string;
  criteria_id: string;
  evaluator_id: string;
  score: number; // 0–100
  comment: string | null;
  is_finalized: boolean;
  created_at: string;
  updated_at: string;
}

// ─── Ranking entry ────────────────────────────────────────────────────────────

export interface RankingEntry {
  rank: number;
  bid_id: string;
  supplier_id: string;
  supplier_name: string;
  total_amount: string;
  currency: string;
  delivery_days: number;
  weighted_score: string | null; // null when blinding is active
  scores_submitted: boolean; // true when the current user has submitted all scores
  is_winner: boolean;
}

// ─── Payloads ─────────────────────────────────────────────────────────────────

/** Criteria configuration request body */
export interface ConfigureCriteriaPayload {
  criteria: {
    name: string;
    weight: number;
    description?: string;
  }[];
}

/** Score submission request body */
export interface SubmitScorePayload {
  scores: {
    criteria_id: string;
    score: number;
    comment?: string;
  }[];
}

/** Winner selection request body */
export interface SelectWinnerPayload {
  bid_id: string;
  justification: string;
}

// ─── Response shapes ──────────────────────────────────────────────────────────

export interface EvaluationCriteriaListResponse {
  data: EvaluationCriteria[];
  price_only_mode: boolean; // true when no criteria defined
}

export interface RankingsResponse {
  data: RankingEntry[];
  evaluation_complete: boolean; // all evaluators have submitted all scores
  price_only_mode: boolean;
}

export interface WinnerSelectionResponse {
  bid_id: string;
  supplier_name: string;
  total_amount: string;
  currency: string;
  justification: string;
  selected_at: string;
}
