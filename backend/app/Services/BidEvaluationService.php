<?php

namespace App\Services;

use App\Jobs\SendBidEvaluationOutcomeJob;
use App\Jobs\WriteAuditLogJob;
use App\Models\Bid;
use App\Models\BidEvaluation;
use App\Models\BidEvaluationCriteria;
use App\Models\Notification;
use App\Models\Supplier;
use App\Models\Tender;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * BidEvaluationService — full bid evaluation lifecycle within a tenant.
 *
 * Responsibilities:
 *  - configureCriteria()        — define / replace weighted evaluation criteria (weights must sum to 100)
 *  - submitScore()              — record an evaluator's score for (criteria, bid); enforces blinding
 *  - calculateWeightedScore()   — compute Σ(avg_score × weight / 100) using bcmath DECIMAL arithmetic
 *  - getRankedComparison()      — ranked list of all bids; supports price-only mode
 *  - selectWinner()             — designate the winning bid with mandatory justification
 *  - isEvaluationFinalized()    — true when all evaluators have submitted scores for all criteria on all bids
 *
 * All monetary / score arithmetic uses PHP BCMath (bcadd, bcmul, bcdiv) at
 * scale=10 for intermediate calculations; final values are formatted to 2 dp.
 *
 * Score blinding:
 *   Individual evaluator scores are hidden until every assigned evaluator has
 *   submitted a score for every criterion on every submitted bid.
 *   getRankedComparison() returns weighted_score: null when blinding is active.
 *
 * Price-only mode:
 *   When a tender has no BidEvaluationCriteria configured, getRankedComparison()
 *   ranks bids by total_amount ascending and calculateWeightedScore() returns null.
 *
 * Requirements: 9.1, 9.2, 9.3, 9.4, 9.5, 9.7, 9.8, 9.9, 9.10
 */
class BidEvaluationService
{
    /**
     * BCMath scale for intermediate arithmetic (10 dp precision).
     */
    private const INTERMEDIATE_SCALE = 10;

    /**
     * BCMath scale for final formatted output (2 dp, matching DECIMAL(8,4) and display).
     */
    private const OUTPUT_SCALE = 2;

    // =========================================================================
    // 1. Configure Criteria
    // =========================================================================

    /**
     * Define (or replace) the weighted evaluation criteria for a tender.
     *
     * All existing criteria are deleted and recreated from the supplied array.
     * The total of all `weight` values must equal exactly 100; otherwise an
     * InvalidArgumentException is thrown.
     *
     * Rejected if evaluation has already been finalized for this tender.
     *
     * @param  Tender  $tender
     * @param  array<int, array{name: string, weight: float|int|string, description?: string|null}>  $criteria
     * @param  User    $actor
     *
     * @throws InvalidArgumentException  when weights do not sum to 100
     * @throws InvalidArgumentException  when evaluation is already finalized
     *
     * Requirements: 9.1
     */
    public function configureCriteria(
        Tender  $tender,
        array   $criteria,
        User    $actor,
        ?string $ipAddress = null,
        ?string $requestId = null,
    ): void {
        if ($this->isEvaluationFinalized($tender)) {
            throw new InvalidArgumentException(
                "Cannot modify evaluation criteria for tender '{$tender->reference_number}': "
                . 'the evaluation has already been finalized.'
            );
        }

        if (empty($criteria)) {
            throw new InvalidArgumentException('At least one evaluation criterion is required.');
        }

        // Validate individual entries and compute weight sum using bcmath
        $weightSum = '0';

        foreach ($criteria as $index => $item) {
            if (empty($item['name'])) {
                throw new InvalidArgumentException("Criterion at index {$index} is missing a name.");
            }

            $weight = (string) ($item['weight'] ?? '');

            if (! is_numeric($weight) || bccomp($weight, '0', self::OUTPUT_SCALE) < 0) {
                throw new InvalidArgumentException(
                    "Criterion '{$item['name']}' has an invalid weight '{$weight}'. "
                    . 'Weight must be a non-negative number.'
                );
            }

            $weightSum = bcadd($weightSum, $weight, self::OUTPUT_SCALE);
        }

        if (bccomp($weightSum, '100', self::OUTPUT_SCALE) !== 0) {
            throw new InvalidArgumentException(
                "Evaluation criteria weights must sum to exactly 100. "
                . "Current sum: {$weightSum}."
            );
        }

        DB::transaction(function () use ($tender, $criteria, $actor, $ipAddress, $requestId) {
            // Delete existing criteria (and cascade-delete their evaluations via DB or manual)
            BidEvaluationCriteria::withoutGlobalScopes()
                ->where('tender_id', $tender->id)
                ->delete();

            // Recreate
            foreach ($criteria as $item) {
                BidEvaluationCriteria::create([
                    'tenant_id'   => $tender->tenant_id,
                    'tender_id'   => $tender->id,
                    'name'        => trim($item['name']),
                    'weight'      => (string) $item['weight'],
                    'max_score'   => '100.00',
                    'description' => $item['description'] ?? null,
                ]);
            }

            WriteAuditLogJob::dispatch(
                $tender->tenant_id,
                $actor->id,
                $actor->getRoleNames()->first() ?? 'procurement_officer',
                'bid_evaluation_criteria.configured',
                'tender',
                $tender->id,
                null,
                [
                    'criteria_count' => count($criteria),
                    'criteria'       => array_map(
                        fn ($c) => ['name' => $c['name'], 'weight' => $c['weight']],
                        $criteria
                    ),
                ],
                $ipAddress ?? '0.0.0.0',
                $requestId,
            )->onQueue('default');
        });
    }

    // =========================================================================
    // 2. Submit Score
    // =========================================================================

    /**
     * Record an evaluator's score for a specific (criteria, bid) pair.
     *
     * Business rules enforced:
     *  - Score must be in the range 0–100 (integer).
     *  - Evaluation must not be finalized; if it is, reject and log the attempt.
     *  - Score blinding: the returned BidEvaluation does NOT include other
     *    evaluators' scores until all evaluators have submitted for all criteria.
     *  - If a score already exists for this (criteria, bid, evaluator) combination,
     *    it is updated (pre-finalization only).
     *
     * @param  BidEvaluationCriteria  $criteria
     * @param  Bid                    $bid
     * @param  int                    $score      0–100
     * @param  User                   $evaluator  The Committee_Member submitting the score
     * @param  User                   $actor      The authenticated user (may be same as evaluator)
     *
     * @throws InvalidArgumentException  when score is out of range
     * @throws InvalidArgumentException  when evaluation is finalized
     *
     * Requirements: 9.2, 9.8, 9.9
     */
    public function submitScore(
        BidEvaluationCriteria $criteria,
        Bid                   $bid,
        int                   $score,
        User                  $evaluator,
        User                  $actor,
        ?string               $ipAddress = null,
        ?string               $requestId = null,
    ): BidEvaluation {
        // Validate score range
        if ($score < 0 || $score > 100) {
            throw new InvalidArgumentException(
                "Score must be between 0 and 100 (received: {$score})."
            );
        }

        // Retrieve the tender for finalization checks
        $tender = Tender::withoutGlobalScopes()->find($criteria->tender_id);

        // Reject if already finalized — log the attempt (Req 9.9)
        if ($this->isEvaluationFinalized($tender)) {
            WriteAuditLogJob::dispatch(
                $criteria->tenant_id,
                $actor->id,
                $actor->getRoleNames()->first() ?? 'committee_member',
                'bid_evaluation.score_modification_rejected',
                'bid_evaluation',
                null,
                null,
                [
                    'reason'      => 'evaluation_finalized',
                    'criteria_id' => $criteria->id,
                    'bid_id'      => $bid->id,
                    'evaluator_id'=> $evaluator->id,
                    'score'       => $score,
                ],
                $ipAddress ?? '0.0.0.0',
                $requestId,
            )->onQueue('default');

            throw new InvalidArgumentException(
                'The evaluation for this tender has been finalized. '
                . 'Scores can no longer be submitted or modified.'
            );
        }

        return DB::transaction(function () use ($criteria, $bid, $score, $evaluator, $actor, $tender, $ipAddress, $requestId) {
            // Create or update the BidEvaluation record
            /** @var BidEvaluation $evaluation */
            $evaluation = BidEvaluation::withoutGlobalScopes()
                ->where('tenant_id', $criteria->tenant_id)
                ->where('criteria_id', $criteria->id)
                ->where('bid_id', $bid->id)
                ->where('evaluator_id', $evaluator->id)
                ->first();

            $isNew  = $evaluation === null;
            $before = $isNew ? null : $evaluation->only(['score', 'is_finalized']);

            if ($isNew) {
                $evaluation = BidEvaluation::create([
                    'tenant_id'    => $criteria->tenant_id,
                    'bid_id'       => $bid->id,
                    'criteria_id'  => $criteria->id,
                    'evaluator_id' => $evaluator->id,
                    'score'        => (string) $score,
                    'is_finalized' => false,
                ]);
            } else {
                $evaluation->update([
                    'score'        => (string) $score,
                    'is_finalized' => false,
                ]);
            }

            WriteAuditLogJob::dispatch(
                $criteria->tenant_id,
                $actor->id,
                $actor->getRoleNames()->first() ?? 'committee_member',
                $isNew ? 'bid_evaluation.score_submitted' : 'bid_evaluation.score_updated',
                'bid_evaluation',
                $evaluation->id,
                $before,
                [
                    'criteria_id'  => $criteria->id,
                    'bid_id'       => $bid->id,
                    'evaluator_id' => $evaluator->id,
                    'score'        => $score,
                ],
                $ipAddress ?? '0.0.0.0',
                $requestId,
            )->onQueue('default');

            return $evaluation->fresh(['criteria', 'bid', 'evaluator']);
        });
    }

    // =========================================================================
    // 3. Calculate Weighted Score
    // =========================================================================

    /**
     * Calculate the weighted total score for a single bid.
     *
     * Formula: Σ( avg_score_for_criteria × weight / 100 )
     *
     * All arithmetic uses bcmath at scale=10 for intermediate operations;
     * the final result is formatted to 2 decimal places as a string.
     *
     * Returns null if the tender is in price-only mode (no criteria configured).
     * Throws an exception if evaluation is not yet complete (score blinding).
     *
     * @throws InvalidArgumentException  when evaluation is not yet finalized
     *
     * Requirements: 9.3
     */
    public function calculateWeightedScore(Bid $bid): ?string
    {
        $tender = Tender::withoutGlobalScopes()->find($bid->tender_id);

        // Price-only mode: no criteria configured
        $criteriaList = BidEvaluationCriteria::withoutGlobalScopes()
            ->where('tender_id', $bid->tender_id)
            ->get();

        if ($criteriaList->isEmpty()) {
            return null; // Price-only mode
        }

        if (! $this->isEvaluationFinalized($tender)) {
            throw new InvalidArgumentException(
                'Weighted scores cannot be calculated until all evaluators have submitted '
                . 'their scores for all criteria on all bids.'
            );
        }

        $totalWeightedScore = '0';

        foreach ($criteriaList as $criterion) {
            // Gather all scores for this criterion on this bid
            $evaluationScores = BidEvaluation::withoutGlobalScopes()
                ->where('criteria_id', $criterion->id)
                ->where('bid_id', $bid->id)
                ->pluck('score');

            if ($evaluationScores->isEmpty()) {
                // No scores — contributes 0 to the total
                continue;
            }

            // Calculate average score using bcmath
            $sum   = '0';
            $count = (string) $evaluationScores->count();

            foreach ($evaluationScores as $rawScore) {
                $sum = bcadd($sum, (string) $rawScore, self::INTERMEDIATE_SCALE);
            }

            $avgScore = bcdiv($sum, $count, self::INTERMEDIATE_SCALE);

            // Weighted contribution: avg_score × weight / 100
            $weight      = (string) $criterion->weight;
            $contribution = bcdiv(
                bcmul($avgScore, $weight, self::INTERMEDIATE_SCALE),
                '100',
                self::INTERMEDIATE_SCALE,
            );

            $totalWeightedScore = bcadd($totalWeightedScore, $contribution, self::INTERMEDIATE_SCALE);
        }

        // Format to 2 decimal places
        return number_format((float) $totalWeightedScore, 2, '.', '');
    }

    // =========================================================================
    // 4. Ranked Comparison Report
    // =========================================================================

    /**
     * Return all submitted bids for a tender ranked by their weighted score.
     *
     * Each entry in the returned array contains:
     *   - bid_id         : string UUID
     *   - supplier_name  : string
     *   - total_amount   : string (DECIMAL)
     *   - weighted_score : string|null  (null when blinding is active or price-only)
     *   - rank           : int
     *
     * Score blinding:
     *   If the evaluation is not yet finalized, weighted_score is set to null for
     *   all bids (scores remain hidden until all evaluators have submitted).
     *
     * Price-only mode:
     *   When no criteria are configured, bids are ranked by total_amount ascending
     *   and weighted_score is always null.
     *
     * Requirements: 9.4, 9.8, 9.10
     */
    public function getRankedComparison(Tender $tender): array
    {
        $bids = Bid::withoutGlobalScopes()
            ->with(['supplier'])
            ->where('tender_id', $tender->id)
            ->whereIn('status', ['submitted', 'under_evaluation', 'won', 'lost'])
            ->get();

        if ($bids->isEmpty()) {
            return [];
        }

        $criteriaList = BidEvaluationCriteria::withoutGlobalScopes()
            ->where('tender_id', $tender->id)
            ->get();

        $isPriceOnly    = $criteriaList->isEmpty();
        $isFinalized    = ! $isPriceOnly && $this->isEvaluationFinalized($tender);

        $rows = [];

        foreach ($bids as $bid) {
            $weightedScore = null;

            if (! $isPriceOnly && $isFinalized) {
                // Safe to compute — all scores are in
                $weightedScore = $this->calculateWeightedScore($bid);
            }

            $rows[] = [
                'bid_id'         => $bid->id,
                'supplier_name'  => $bid->supplier?->organization_name ?? 'Unknown Supplier',
                'total_amount'   => (string) $bid->total_amount,
                'weighted_score' => $weightedScore,
                '_sort_key'      => $isPriceOnly
                    ? (float) $bid->total_amount
                    : ($weightedScore !== null ? (float) $weightedScore : PHP_INT_MAX),
                '_sort_dir'      => $isPriceOnly ? 'asc' : 'desc',
            ];
        }

        // Sort: price-only → ascending by total_amount; scored → descending by weighted_score
        usort($rows, function (array $a, array $b) use ($isPriceOnly) {
            if ($isPriceOnly) {
                return $a['_sort_key'] <=> $b['_sort_key']; // ascending
            }

            return $b['_sort_key'] <=> $a['_sort_key']; // descending
        });

        // Assign ranks and clean up internal sort keys
        $result = [];
        foreach ($rows as $rank => $row) {
            unset($row['_sort_key'], $row['_sort_dir']);
            $row['rank'] = $rank + 1;
            $result[]    = $row;
        }

        return $result;
    }

    // =========================================================================
    // 5. Select Winner
    // =========================================================================

    /**
     * Designate the winning bid and notify all suppliers of the outcome.
     *
     * Steps:
     *  1. Validate $justification is non-empty.
     *  2. Mark the winning bid status = 'awarded'; all other bids = 'lost'.
     *  3. Store justification + winning_bid_id on the tender record.
     *  4. Transition tender status to 'awarded'.
     *  5. Dispatch outcome notifications to winning and non-winning suppliers.
     *  6. Log the action via audit log.
     *
     * @throws InvalidArgumentException  when $justification is empty
     *
     * Requirements: 9.5, 9.6, 9.7
     */
    public function selectWinner(
        Tender  $tender,
        Bid     $winningBid,
        string  $justification,
        User    $actor,
        ?string $ipAddress = null,
        ?string $requestId = null,
    ): void {
        if (empty(trim($justification))) {
            throw new InvalidArgumentException(
                'A justification is required when selecting a winning bid.'
            );
        }

        // Winning bid must belong to this tender
        if ($winningBid->tender_id !== $tender->id) {
            throw new InvalidArgumentException(
                'The specified winning bid does not belong to this tender.'
            );
        }

        DB::transaction(function () use ($tender, $winningBid, $justification, $actor, $ipAddress, $requestId) {
            $before = [
                'status'              => $tender->status,
                'winning_bid_id'      => $tender->winning_bid_id,
                'winner_justification'=> $tender->winner_justification,
            ];

            // Mark winner
            $winningBid->update(['status' => 'won']);

            // Mark all other submitted/under_evaluation bids as lost
            Bid::withoutGlobalScopes()
                ->where('tender_id', $tender->id)
                ->where('id', '!=', $winningBid->id)
                ->whereIn('status', ['submitted', 'under_evaluation'])
                ->update(['status' => 'lost']);

            // Update tender record
            $tender->update([
                'status'               => 'awarded',
                'winning_bid_id'       => $winningBid->id,
                'winner_justification' => $justification,
            ]);

            WriteAuditLogJob::dispatch(
                $tender->tenant_id,
                $actor->id,
                $actor->getRoleNames()->first() ?? 'procurement_officer',
                'bid_evaluation.winner_selected',
                'tender',
                $tender->id,
                $before,
                [
                    'status'               => 'awarded',
                    'winning_bid_id'       => $winningBid->id,
                    'winner_justification' => $justification,
                ],
                $ipAddress ?? '0.0.0.0',
                $requestId,
            )->onQueue('default');

            // Dispatch notifications to all bidding suppliers
            $this->dispatchOutcomeNotifications($tender, $winningBid, $justification);
        });
    }

    // =========================================================================
    // 6. Is Evaluation Finalized
    // =========================================================================

    /**
     * Determine whether all assigned evaluators have submitted scores for every
     * evaluation criterion on every submitted bid.
     *
     * Returns true when:
     *  - The tender has at least one criterion configured.
     *  - The tender has at least one submitted bid.
     *  - The tender has at least one assigned evaluator.
     *  - For every (criteria × bid × evaluator) combination, a BidEvaluation
     *    record exists in the database.
     *
     * Returns false in all other cases, including price-only mode.
     *
     * Requirements: 9.8
     */
    public function isEvaluationFinalized(Tender $tender): bool
    {
        // Reload if assigned_evaluators may be stale
        $assignedEvaluators = $tender->assigned_evaluators ?? [];

        if (empty($assignedEvaluators)) {
            return false;
        }

        $criteriaIds = BidEvaluationCriteria::withoutGlobalScopes()
            ->where('tender_id', $tender->id)
            ->pluck('id')
            ->toArray();

        if (empty($criteriaIds)) {
            return false; // Price-only mode — no criteria, no formal finalization
        }

        $bidIds = Bid::withoutGlobalScopes()
            ->where('tender_id', $tender->id)
            ->whereIn('status', ['submitted', 'under_evaluation', 'won', 'lost'])
            ->pluck('id')
            ->toArray();

        if (empty($bidIds)) {
            return false;
        }

        // Total expected evaluation records
        $expectedCount = count($criteriaIds) * count($bidIds) * count($assignedEvaluators);

        // Count actual evaluation records for this tender
        $actualCount = BidEvaluation::withoutGlobalScopes()
            ->whereIn('criteria_id', $criteriaIds)
            ->whereIn('bid_id', $bidIds)
            ->whereIn('evaluator_id', $assignedEvaluators)
            ->count();

        return $actualCount >= $expectedCount;
    }

    // =========================================================================
    // Private: Notification dispatch
    // =========================================================================

    /**
     * Dispatch bid outcome notifications to all suppliers who submitted a bid
     * on this tender.
     *
     * Requirements: 9.6
     */
    private function dispatchOutcomeNotifications(Tender $tender, Bid $winningBid, string $justification): void
    {
        $bids = Bid::withoutGlobalScopes()
            ->with(['supplier'])
            ->where('tender_id', $tender->id)
            ->whereIn('status', ['won', 'lost'])
            ->get();

        foreach ($bids as $bid) {
            $supplier = $bid->supplier;

            if (! $supplier) {
                continue;
            }

            // Resolve the user account linked to the supplier
            $userId = $supplier->user_id;

            if (! $userId) {
                // No portal user linked — fall back to in-app notification via contact_email lookup
                $this->createFallbackNotification($tender, $bid, $supplier, $justification);
                continue;
            }

            $outcome = ($bid->id === $winningBid->id) ? 'won' : 'lost';

            SendBidEvaluationOutcomeJob::dispatch(
                tenantId:        $tender->tenant_id,
                userId:          $userId,
                tenderId:        $tender->id,
                tenderTitle:     $tender->title,
                tenderReference: $tender->reference_number,
                bidId:           $bid->id,
                outcome:         $outcome,
                justification:   $outcome === 'won' ? $justification : null,
            )->onQueue('notifications');
        }
    }

    /**
     * Create a direct in-app notification for a supplier that has no linked
     * portal user account (best-effort delivery).
     */
    private function createFallbackNotification(Tender $tender, Bid $bid, Supplier $supplier, string $justification): void
    {
        try {
            $outcome  = ($bid->status === 'won') ? 'won' : 'lost';
            $isWinner = $outcome === 'won';

            Notification::withoutGlobalScopes()->create([
                'tenant_id'  => $tender->tenant_id,
                'user_id'    => null, // no portal user
                'event_type' => 'bid_evaluation_completed',
                'title'      => $isWinner
                    ? "Congratulations! Your bid won: {$tender->title}"
                    : "Bid outcome: {$tender->title}",
                'message'    => $isWinner
                    ? "Your bid was selected as the winner. Justification: {$justification}"
                    : 'Your bid was not selected for this tender. Thank you for participating.',
                'data'       => [
                    'tender_id'        => $tender->id,
                    'tender_reference' => $tender->reference_number,
                    'bid_id'           => $bid->id,
                    'supplier_id'      => $supplier->id,
                    'outcome'          => $outcome,
                ],
                'is_read'    => false,
            ]);
        } catch (\Throwable $e) {
            Log::error('BidEvaluationService: failed to create fallback notification', [
                'tender_id'   => $tender->id,
                'supplier_id' => $supplier->id,
                'error'       => $e->getMessage(),
            ]);
        }
    }
}
