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
 * @OA\Tag(name="Bid Evaluation", description="Weighted scoring criteria, score submission, rankings, and winner selection.")
 *
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
     * @OA\Post(
     *     path="/tenders/{tender}/evaluation/criteria",
     *     operationId="configureBidCriteria",
     *     tags={"Bid Evaluation"},
     *     summary="Configure evaluation criteria",
     *     description="Defines (or replaces) weighted evaluation criteria. All weights must sum to 100.",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="tender", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\RequestBody(required=true, @OA\JsonContent(required={"criteria"}, @OA\Property(property="criteria", type="array", @OA\Items(@OA\Property(property="name", type="string", example="Technical Compliance"), @OA\Property(property="weight", type="number", example=40), @OA\Property(property="max_score", type="number", example=100))))),
     *     @OA\Response(response=200, description="Criteria configured.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/BidEvaluationCriteriaResource")), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", nullable=true, example=null))),
     *     @OA\Response(response=422, description="Weights do not sum to 100.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Define (or replace) the weighted evaluation criteria for a tender.
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
     * @OA\Get(
     *     path="/tenders/{tender}/evaluation/criteria",
     *     operationId="getBidCriteria",
     *     tags={"Bid Evaluation"},
     *     summary="Get evaluation criteria",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="tender", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\Response(response=200, description="Criteria list.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/BidEvaluationCriteriaResource")), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", nullable=true, example=null)))
     * )
     *
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
     * @OA\Post(
     *     path="/tenders/{tender}/bids/{bid}/evaluation/scores",
     *     operationId="submitBidScore",
     *     tags={"Bid Evaluation"},
     *     summary="Submit score for a bid",
     *     description="Records an evaluator's score for a specific (bid, criteria) pair. Score 0–100.",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="tender", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\Parameter(name="bid", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\RequestBody(required=true, @OA\JsonContent(required={"criteria_id","score"}, @OA\Property(property="criteria_id", type="string", format="uuid"), @OA\Property(property="score", type="integer", minimum=0, maximum=100, example=85), @OA\Property(property="comment", type="string", nullable=true))),
     *     @OA\Response(response=201, description="Score submitted.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", type="object"), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", nullable=true, example=null))),
     *     @OA\Response(response=422, description="Evaluation finalized or invalid score.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Record an evaluator's score for a specific (bid, criteria) pair.
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
     * @OA\Get(
     *     path="/tenders/{tender}/evaluation/rankings",
     *     operationId="getBidRankings",
     *     tags={"Bid Evaluation"},
     *     summary="Get ranked bid comparison",
     *     description="Returns all submitted bids ranked by weighted score. Score blinding active when not all evaluators have submitted. In price-only mode, bids ranked by total_amount ascending.",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="tender", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\Response(response=200, description="Ranked comparison.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/BidResource")), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", nullable=true, example=null)))
     * )
     *
     * Return all submitted bids for a tender ranked by their weighted score.
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
     * @OA\Post(
     *     path="/tenders/{tender}/evaluation/winner",
     *     operationId="selectBidWinner",
     *     tags={"Bid Evaluation"},
     *     summary="Select winning bid",
     *     description="Marks the winning bid as 'won', all others as 'lost', updates tender status to 'awarded', and notifies all suppliers.",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="tender", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\RequestBody(required=true, @OA\JsonContent(required={"bid_id","justification"}, @OA\Property(property="bid_id", type="string", format="uuid"), @OA\Property(property="justification", type="string", example="Highest weighted score with best delivery time."))),
     *     @OA\Response(response=200, description="Winner selected, tender awarded.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", ref="#/components/schemas/TenderResource"), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", nullable=true, example=null))),
     *     @OA\Response(response=422, description="Empty justification or service rule failure.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Designate the winning bid and notify all suppliers of the outcome.
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
