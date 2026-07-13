/**
 * Optimistic-update mutations for Approval actions.
 *
 * Hooks:
 *   useApproveDocument   — approve a pending approval record;
 *                          immediately removes it from every pending list in cache
 *   useRejectDocument    — reject a pending approval record;
 *                          immediately removes it from every pending list in cache
 *   useReturnForRevision — return for revision;
 *                          immediately removes it from every pending list in cache
 *
 * Optimistic update pattern (all three share the same strategy):
 *   1. cancelQueries     — stop any in-flight fetches that would overwrite our update
 *   2. getQueriesData    — snapshot current cache for rollback
 *   3. setQueriesData    — remove acted-upon item from every variant of the pending list
 *   4. onError           — restore snapshot on server failure
 *   5. onSettled         — always re-validate to sync with server truth
 *
 * These are the canonical mutation hooks for the approval workflow.
 * The implementations in `src/hooks/useApprovals.ts` are identical —
 * this file re-exports them from a single mutations barrel.
 *
 * Validates: Requirements 6.3, 6.4, 6.5, 22.5, 22.7
 */

export {
  useApproveDocument,
  useRejectDocument,
  useReturnForRevision,
} from "@/hooks/useApprovals";
