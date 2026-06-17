/**
 * API client functions for Bid Evaluation System.
 *
 * Endpoints:
 *   GET  /tenders/{id}/evaluation/criteria   — list configured criteria
 *   POST /tenders/{id}/evaluation/criteria   — configure criteria
 *   POST /tenders/{id}/bids/{bidId}/evaluation/scores — submit evaluator scores
 *   GET  /tenders/{id}/evaluation/rankings   — ranked comparison
 *   POST /tenders/{id}/evaluation/winner     — select winner
 *
 * Validates: Requirements 9.1, 9.2, 9.3, 9.4, 9.5, 9.6
 */

import { apiGet, apiPost } from '@/lib/api/client';
import type { ApiResponse } from '@/types/api.types';
import type {
  EvaluationCriteriaListResponse,
  RankingsResponse,
  WinnerSelectionResponse,
  ConfigureCriteriaPayload,
  SubmitScorePayload,
  SelectWinnerPayload,
} from '@/types/evaluation';

// ─── Criteria ─────────────────────────────────────────────────────────────────

/**
 * Retrieve the list of evaluation criteria for a tender.
 */
export async function getEvaluationCriteria(
  tenderId: string,
): Promise<ApiResponse<EvaluationCriteriaListResponse>> {
  return apiGet<ApiResponse<EvaluationCriteriaListResponse>>(
    `/tenders/${tenderId}/evaluation/criteria`,
  );
}

/**
 * Configure (replace) evaluation criteria for a tender.
 * Weights must sum to 100.
 * Only Procurement_Officer / Tenant_Admin may call this.
 */
export async function configureCriteria(
  tenderId: string,
  payload: ConfigureCriteriaPayload,
): Promise<ApiResponse<EvaluationCriteriaListResponse>> {
  return apiPost<ApiResponse<EvaluationCriteriaListResponse>>(
    `/tenders/${tenderId}/evaluation/criteria`,
    payload,
  );
}

// ─── Scores ───────────────────────────────────────────────────────────────────

/**
 * Submit evaluation scores for a bid from the current authenticated evaluator.
 * Scores are blinded until all evaluators have submitted.
 */
export async function submitEvaluationScores(
  tenderId: string,
  bidId: string,
  payload: SubmitScorePayload,
): Promise<ApiResponse<null>> {
  return apiPost<ApiResponse<null>>(
    `/tenders/${tenderId}/bids/${bidId}/evaluation/scores`,
    payload,
  );
}

// ─── Rankings ─────────────────────────────────────────────────────────────────

/**
 * Retrieve ranked comparison of all bids.
 * weighted_score fields will be null when score blinding is active.
 */
export async function getEvaluationRankings(
  tenderId: string,
): Promise<ApiResponse<RankingsResponse>> {
  return apiGet<ApiResponse<RankingsResponse>>(
    `/tenders/${tenderId}/evaluation/rankings`,
  );
}

// ─── Winner selection ─────────────────────────────────────────────────────────

/**
 * Select the winning bid with a mandatory justification comment.
 * Only Procurement_Officer / Tenant_Admin may call this.
 */
export async function selectWinner(
  tenderId: string,
  payload: SelectWinnerPayload,
): Promise<ApiResponse<WinnerSelectionResponse>> {
  return apiPost<ApiResponse<WinnerSelectionResponse>>(
    `/tenders/${tenderId}/evaluation/winner`,
    payload,
  );
}
