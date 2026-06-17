/**
 * TanStack Query hooks for Bid Evaluation System.
 *
 * Hooks:
 *   useEvaluationCriteria  — query: fetch criteria for a tender
 *   useConfigureCriteria   — mutation: configure/replace criteria
 *   useSubmitScore         — mutation: submit evaluator scores for a bid
 *   useRankings            — query: ranked comparison of bids
 *   useSelectWinner        — mutation: select winning bid with justification
 *
 * Validates: Requirements 9.1, 9.2, 9.3, 9.4, 9.5, 22.5
 */

import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import {
  getEvaluationCriteria,
  configureCriteria,
  submitEvaluationScores,
  getEvaluationRankings,
  selectWinner,
} from '@/lib/api/evaluations';
import type {
  ConfigureCriteriaPayload,
  SubmitScorePayload,
  SelectWinnerPayload,
} from '@/types/evaluation';

// ─── Query keys ───────────────────────────────────────────────────────────────

export const evaluationQueryKeys = {
  all: ['evaluation'] as const,
  criteria: (tenderId: string) => ['evaluation', 'criteria', tenderId] as const,
  rankings: (tenderId: string) => ['evaluation', 'rankings', tenderId] as const,
};

// ─── Queries ──────────────────────────────────────────────────────────────────

/**
 * Fetch evaluation criteria configured for a tender.
 */
export function useEvaluationCriteria(tenderId: string) {
  return useQuery({
    queryKey: evaluationQueryKeys.criteria(tenderId),
    queryFn: () => getEvaluationCriteria(tenderId),
    enabled: Boolean(tenderId),
  });
}

/**
 * Fetch ranked comparison of bids for a tender.
 * weighted_score fields will be null while score blinding is active.
 */
export function useRankings(tenderId: string) {
  return useQuery({
    queryKey: evaluationQueryKeys.rankings(tenderId),
    queryFn: () => getEvaluationRankings(tenderId),
    enabled: Boolean(tenderId),
  });
}

// ─── Mutations ────────────────────────────────────────────────────────────────

/**
 * Configure (replace) evaluation criteria for a tender.
 * Invalidates criteria and rankings queries on success.
 */
export function useConfigureCriteria(tenderId: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (payload: ConfigureCriteriaPayload) =>
      configureCriteria(tenderId, payload),
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: evaluationQueryKeys.criteria(tenderId),
      });
      queryClient.invalidateQueries({
        queryKey: evaluationQueryKeys.rankings(tenderId),
      });
    },
  });
}

/**
 * Submit evaluation scores for a specific bid from the current evaluator.
 * Invalidates rankings so score-blinding can be lifted once all scores are in.
 */
export function useSubmitScore(tenderId: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ bidId, payload }: { bidId: string; payload: SubmitScorePayload }) =>
      submitEvaluationScores(tenderId, bidId, payload),
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: evaluationQueryKeys.rankings(tenderId),
      });
    },
  });
}

/**
 * Select the winning bid with a mandatory justification comment.
 * Invalidates rankings and the tender detail on success.
 */
export function useSelectWinner(tenderId: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (payload: SelectWinnerPayload) => selectWinner(tenderId, payload),
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: evaluationQueryKeys.rankings(tenderId),
      });
      // Invalidate the tender detail so status updates to 'awarded'
      queryClient.invalidateQueries({
        queryKey: ['tenders', 'detail', tenderId],
      });
    },
  });
}
