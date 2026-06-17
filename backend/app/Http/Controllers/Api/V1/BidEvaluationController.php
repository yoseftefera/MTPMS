<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\BidEvaluation\ConfigureCriteriaRequest;
use App\Http\Requests\V1\BidEvaluation\SelectWinnerRequest;
use App\Http\Requests\V1\BidEvaluation\SubmitScoreRequest;
use App\Http\Resources\V1\BidEvaluationCriteriaResource;
use App\Http\Resources\V1\BidEvaluationResource;
use App\Http\Resources\V1\TenderResource;
use App\Models\Bid;
use App\Models\BidEvaluationCriteria;
use App\Models\Tender;
use App\Services\BidEvaluationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

/**
 * BidEvaluationController — thin HTTP layer for the bid evaluation lifecycle.
 *
 * Endpoints (all nested under /api/v1/tenders/{tender}/evaluation):
 *   POST  /api/v1/tenders/{tender}/evaluation/criteria              — configure criteria (Procurement_Officer, Tenant_Admin)
 *   GET   /api/v1/tenders/{tender}/evaluation/criteria              — list criteria (Procurement_Officer, Tenant_Admin, Committee_Member)
 *   POST  /api/v1/tenders/{tender}/bids/{bid}/evaluation/scores     — submit a score (Committee_Member, Tenant_Admin)
 *   GET   /api/v1/tenders/{tender}/evaluation/rankings              — ranked comparison (Procurement_Officer, Tenant_Admin, Committee_Member)
 *   POST  /api/v1/tenders/{tender}/evaluation/winner               — select the winning bid (Procurement_Officer, Tenant_Admin)
 *
 * All queries are automatically scoped to the active tenant via HasTenantScope.
 * Route model binding for {tender} and {bid} implicitly checks the tenant scope.
 *
 * Permission gates are enforced at route level via role.check middleware:
 *   - tenders.create    — storeCriteria
 *   - tenders.evaluate  — submitScore, rankings
 *   - tenders.publish   — selectWinner
 *
 * Requirements: 9.1, 9.2, 9.3, 9.4, 9.5, 9.6
 */
class BidEvaluationController extends Controller
{
    public function __construct(private readonly BidEvaluationService $service) {}

    // =========================================================================
    // POST /api/v1/tenders/{tender}/evaluation/criteria
    // =========================================================================

    /**
     * Define (or replace) the weighted evaluation criteria for a tender.
     *
     * All weights must sum to exactly 100; otherwise HTTP 422 is returned.
     * Returns HTTP 422 when the evaluation has already been finalized.
     *
     * Requirements: 9.1
     */
    public function storeCriteria(ConfigureCriteriaRequest $request, Tender $tender): JsonResponse
    {
        $actor = Auth::guard('api')->user();

        try {
            $this->service->configureCriteria(
                tender:    $tender,
                criteria:  $request->validated()['criteria'],
                actor:     $actor,
                ipAddress: $request->ip(),
                requestId: $request->header('X-Request-ID'),
            );
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422, ['general' => [$e->getMessage()]]);
        }

        // Reload fresh criteria after the replace operation
        $criteria = BidEvaluationCriteria::withoutGlobalScopes()
            ->where('tender_id', $tender->id)
            ->orderBy('created_at')
            ->get();

        return $this->success(
            data:    BidEvaluationCriteriaResource::collection($criteria),
            message: 'Evaluation criteria configured successfully.',
        );
    }

    // =========================================================================
    // GET /api/v1/tenders/{tender}/evaluation/criteria
    // =========================================================================

    /**
     * Return the configured evaluation criteria for a tender.
     *
     * Requirements: 9.1
     */
    public function criteria(Tender $tender): JsonResponse
    {
        $criteria = BidEvaluationCriteria::withoutGlobalScopes()
            ->where('tender_id', $tender->id)
            ->orderBy('created_at')
            ->get();

        return $this->success(
            data:    BidEvaluationCriteriaResource::collection($criteria),
            message: 'Evaluation criteria retrieved successfully.',
        );
    }

    // =========================================================================
    // POST /api/v1/tenders/{tender}/bids/{bid}/evaluation/scores
    // =========================================================================

    /**
     * Record an evaluator's score for a specific (bid, criteria) pair.
     *
     * Business rules enforced by the service:
     *  - Score must be in the range 0–100.
     *  - Evaluation must not be finalized; if it is, HTTP 422 is returned.
     *  - If a score already exists for (criteria, bid, evaluator), it is updated.
     *
     * Returns HTTP 404 when the criteria_id does not belong to this tender.
     * Returns HTTP 422 when the bid does not belong to this tender.
     *
     * Requirements: 9.2, 9.8, 9.9
     */
    public function submitScore(SubmitScoreRequest $request, Tender $tender, Bid $bid): JsonResponse
    {
        // Guard: bid must belong to this tender
        if ($bid->tender_id !== $tender->id) {
            return $this->error('Bid not found.', 404);
        }

        $criteriaId = $request->validated()['criteria_id'];

        // Guard: criteria must belong to this tender
        $criteria = BidEvaluationCriteria::withoutGlobalScopes()
            ->where('id', $criteriaId)
            ->where('tender_id', $tender->id)
            ->first();

        if (! $criteria) {
            return $this->error(
                message: 'The specified criteria does not belong to this tender.',
                status:  404,
                errors:  ['criteria_id' => ['Criteria not found for this tender.']],
            );
        }

        $actor = Auth::guard('api')->user();

        try {
            $evaluation = $this->service->submitScore(
                criteria:  $criteria,
                bid:       $bid,
                score:     (int) $request->validated()['score'],
                evaluator: $actor,
                actor:     $actor,
                ipAddress: $request->ip(),
                requestId: $request->header('X-Request-ID'),
            );
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422, ['general' => [$e->getMessage()]]);
        }

        return $this->created(
            data:    new BidEvaluationResource($evaluation),
            message: 'Score submitted successfully.',
        );
    }

    // =========================================================================
    // GET /api/v1/tenders/{tender}/evaluation/rankings
    // =========================================================================

    /**
     * Return all submitted bids for a tender ranked by their weighted score.
     *
     * When score blinding is active (not all evaluators have submitted all scores),
     * `weighted_score` is null for every entry. In price-only mode, bids are
     * ranked by total_amount ascending.
     *
     * Requirements: 9.4, 9.8, 9.10
     */
    public function rankings(Tender $tender): JsonResponse
    {
        $ranked = $this->service->getRankedComparison($tender);

        return $this->success(
            data:    $ranked,
            message: 'Ranked comparison retrieved successfully.',
        );
    }

    // =========================================================================
    // POST /api/v1/tenders/{tender}/evaluation/winner
    // =========================================================================

    /**
     * Designate the winning bid and notify all suppliers of the outcome.
     *
     * Steps (delegated to BidEvaluationService::selectWinner):
     *  1. Validate justification is non-empty.
     *  2. Mark winning bid status = 'won'; all others = 'lost'.
     *  3. Update tender status to 'awarded'.
     *  4. Dispatch outcome notifications to winning and non-winning suppliers
     *     via SendBidEvaluationOutcomeJob (handled inside the service).
     *  5. Record in the audit log.
     *
     * Returns HTTP 404 when bid_id does not belong to this tender.
     * Returns HTTP 422 when justification is empty or any other service rule fails.
     *
     * Requirements: 9.5, 9.6, 9.7
     */
    public function selectWinner(SelectWinnerRequest $request, Tender $tender): JsonResponse
    {
        $validated  = $request->validated();
        $actor      = Auth::guard('api')->user();

        // Resolve the winning bid — must belong to this tender
        $winningBid = Bid::withoutGlobalScopes()
            ->where('id', $validated['bid_id'])
            ->where('tender_id', $tender->id)
            ->first();

        if (! $winningBid) {
            return $this->error(
                message: 'The specified bid does not belong to this tender.',
                status:  404,
                errors:  ['bid_id' => ['Bid not found for this tender.']],
            );
        }

        try {
            $this->service->selectWinner(
                tender:        $tender,
                winningBid:    $winningBid,
                justification: $validated['justification'],
                actor:         $actor,
                ipAddress:     $request->ip(),
                requestId:     $request->header('X-Request-ID'),
            );
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422, ['general' => [$e->getMessage()]]);
        }

        // Return the refreshed tender record
        $updatedTender = $tender->fresh(['documents', 'createdBy', 'winningBid']);

        return $this->success(
            data:    new TenderResource($updatedTender),
            message: 'Winner selected successfully. Suppliers have been notified.',
        );
    }
}
