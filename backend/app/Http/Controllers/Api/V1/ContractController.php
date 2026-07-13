<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\Contract\AmendContractRequest;
use App\Http\Requests\V1\Contract\StoreContractRequest;
use App\Http\Requests\V1\Contract\TerminateContractRequest;
use App\Http\Requests\V1\Contract\UploadContractDocumentRequest;
use App\Http\Resources\V1\ContractDocumentResource;
use App\Http\Resources\V1\ContractResource;
use App\Models\Contract;
use App\Services\ContractService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * @OA\Tag(name="Contracts", description="Contract lifecycle: creation, activation, amendment, termination, and document uploads.")
 *
 * ContractController — thin controller for the contract lifecycle.
 *
 * Endpoints:
 *   GET    /api/v1/contracts                               — paginated list with filters
 *   POST   /api/v1/contracts                               — create contract (draft)
 *   GET    /api/v1/contracts/{contract}                    — single contract with details
 *   PUT    /api/v1/contracts/{contract}                    — not used (amend via /amend)
 *   POST   /api/v1/contracts/{contract}/activate           — activate contract (draft → active)
 *   POST   /api/v1/contracts/{contract}/amend              — amend contract with reason
 *   POST   /api/v1/contracts/{contract}/terminate          — terminate contract (active → terminated)
 *   POST   /api/v1/contracts/{contract}/documents          — upload document
 *
 * All queries are automatically scoped to the active tenant via HasTenantScope.
 * Route model binding returns HTTP 404 when the contract belongs to a different tenant.
 *
 * Requirements: 11.1, 11.2, 11.5, 11.6, 11.7, 11.8, 11.9, 11.10
 */
class ContractController extends Controller
{
    public function __construct(private readonly ContractService $service) {}

    // -------------------------------------------------------------------------
    // GET /api/v1/contracts
    // -------------------------------------------------------------------------

    /**
     * @OA\Get(path="/contracts", operationId="listContracts", tags={"Contracts"}, summary="List contracts",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="status", in="query", required=false, @OA\Schema(type="string", enum={"draft","pending_bond","active","expired","terminated","renewed"})),
     *     @OA\Parameter(name="supplier_id", in="query", required=false, @OA\Schema(type="string", format="uuid")),
     *     @OA\Parameter(name="date_from", in="query", required=false, @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="date_to", in="query", required=false, @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="per_page", in="query", required=false, @OA\Schema(type="integer", default=20)),
     *     @OA\Response(response=200, description="Contracts list.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/ContractResource")), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", ref="#/components/schemas/PaginationMeta"))),
     *     @OA\Response(response=401, description="Unauthenticated.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Return a paginated list of contracts, with optional filters.
     *
     * Requirements: 11.1
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 20), 100);

        $filters = array_filter([
            'status'      => $request->query('status'),
            'supplier_id' => $request->query('supplier_id'),
            'date_from'   => $request->query('date_from'),
            'date_to'     => $request->query('date_to'),
        ], fn ($v) => $v !== null && $v !== '');

        $paginator = $this->service->search($filters, $perPage);

        return $this->paginated(
            paginator: $paginator,
            data:      ContractResource::collection($paginator->items()),
            message:   'Contracts retrieved successfully.',
        );
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/contracts
    // -------------------------------------------------------------------------

    /**
     * @OA\Post(path="/contracts", operationId="createContract", tags={"Contracts"}, summary="Create contract",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\RequestBody(required=true, @OA\JsonContent(required={"supplier_id","title","scope","total_value","start_date","end_date","payment_terms"}, @OA\Property(property="supplier_id", type="string", format="uuid"), @OA\Property(property="purchase_order_id", type="string", format="uuid", nullable=true), @OA\Property(property="tender_id", type="string", format="uuid", nullable=true), @OA\Property(property="title", type="string"), @OA\Property(property="scope", type="string"), @OA\Property(property="total_value", type="string", example="500000.00"), @OA\Property(property="currency", type="string", example="USD"), @OA\Property(property="start_date", type="string", format="date"), @OA\Property(property="end_date", type="string", format="date"), @OA\Property(property="payment_terms", type="string"))),
     *     @OA\Response(response=201, description="Contract created.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", ref="#/components/schemas/ContractResource"), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", nullable=true, example=null))),
     *     @OA\Response(response=422, description="Validation error.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Create a new contract in draft status.
     *
     * Requirements: 11.1
     */
    public function store(StoreContractRequest $request): JsonResponse
    {
        $user = Auth::guard('api')->user();

        try {
            $contract = $this->service->create($request->validated(), $user);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422, ['general' => [$e->getMessage()]]);
        }

        return $this->created(
            data:    new ContractResource($contract),
            message: 'Contract created successfully.',
        );
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/contracts/{contract}
    // -------------------------------------------------------------------------

    /**
     * @OA\Get(path="/contracts/{contract}", operationId="showContract", tags={"Contracts"}, summary="Get contract",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="contract", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\Response(response=200, description="Contract with amendments and documents.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", ref="#/components/schemas/ContractResource"), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", nullable=true, example=null))),
     *     @OA\Response(response=404, description="Not found.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Return a single contract with all related details.
     *
     * Requirements: 11.1, 11.5
     */
    public function show(Contract $contract): JsonResponse
    {
        $contract->load([
            'supplier',
            'purchaseOrder',
            'tender',
            'createdBy',
            'amendments.amendedBy',
            'documents.uploadedBy',
        ]);

        return $this->success(
            data:    new ContractResource($contract),
            message: 'Contract retrieved successfully.',
        );
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/contracts/{contract}/activate
    // -------------------------------------------------------------------------

    /**
     * @OA\Post(path="/contracts/{contract}/activate", operationId="activateContract", tags={"Contracts"}, summary="Activate contract",
     *     description="Transitions contract from draft to active. Blocked if no performance bond document is uploaded (HTTP 422).",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="contract", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\Response(response=200, description="Contract activated.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", ref="#/components/schemas/ContractResource"), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", nullable=true, example=null))),
     *     @OA\Response(response=422, description="No performance bond uploaded or invalid state.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Activate a contract (draft → active).
     *
     * Requirements: 11.7, 11.8
     */
    public function activate(Contract $contract): JsonResponse
    {
        $user = Auth::guard('api')->user();

        try {
            $this->service->activate($contract, $user);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422, ['general' => [$e->getMessage()]]);
        }

        return $this->success(
            data:    new ContractResource($contract->fresh(['supplier', 'purchaseOrder', 'tender', 'createdBy', 'amendments', 'documents'])),
            message: 'Contract activated successfully.',
        );
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/contracts/{contract}/amend
    // -------------------------------------------------------------------------

    /**
     * @OA\Post(path="/contracts/{contract}/amend", operationId="amendContract", tags={"Contracts"}, summary="Amend contract",
     *     description="Amends contract with documented reason. Creates version history entry.",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="contract", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\RequestBody(required=true, @OA\JsonContent(required={"reason"}, @OA\Property(property="reason", type="string", example="Scope expanded to include additional deliverables."), @OA\Property(property="total_value", type="string", nullable=true, example="600000.00"), @OA\Property(property="end_date", type="string", format="date", nullable=true), @OA\Property(property="payment_terms", type="string", nullable=true))),
     *     @OA\Response(response=200, description="Contract amended.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", ref="#/components/schemas/ContractResource"), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", nullable=true, example=null))),
     *     @OA\Response(response=422, description="Validation error.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Amend a contract with a documented reason.
     *
     * Requirements: 11.5, 11.6
     */
    public function amend(AmendContractRequest $request, Contract $contract): JsonResponse
    {
        $user      = Auth::guard('api')->user();
        $validated = $request->validated();
        $reason    = $validated['reason'];
        $changes   = array_diff_key($validated, array_flip(['reason']));

        try {
            $this->service->amend($contract, $changes, $reason, $user);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422, ['general' => [$e->getMessage()]]);
        }

        $contract->load([
            'supplier',
            'purchaseOrder',
            'tender',
            'createdBy',
            'amendments.amendedBy',
            'documents.uploadedBy',
        ]);

        return $this->success(
            data:    new ContractResource($contract->fresh([
                'supplier',
                'purchaseOrder',
                'tender',
                'createdBy',
                'amendments.amendedBy',
                'documents.uploadedBy',
            ])),
            message: 'Contract amended successfully.',
        );
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/contracts/{contract}/terminate
    // -------------------------------------------------------------------------

    /**
     * @OA\Post(path="/contracts/{contract}/terminate", operationId="terminateContract", tags={"Contracts"}, summary="Terminate contract",
     *     description="Terminates an active contract with a documented reason.",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="contract", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\RequestBody(required=true, @OA\JsonContent(required={"reason"}, @OA\Property(property="reason", type="string", example="Supplier failed to meet contractual obligations."))),
     *     @OA\Response(response=200, description="Contract terminated.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", ref="#/components/schemas/ContractResource"), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", nullable=true, example=null))),
     *     @OA\Response(response=422, description="Contract not in active status.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Terminate a contract (active → terminated).
     *
     * Requirements: 11.10
     */
    public function terminate(TerminateContractRequest $request, Contract $contract): JsonResponse
    {
        $user   = Auth::guard('api')->user();
        $reason = $request->validated()['reason'];

        try {
            $this->service->terminate($contract, $reason, $user);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422, ['general' => [$e->getMessage()]]);
        }

        return $this->success(
            data:    new ContractResource($contract->fresh(['supplier', 'purchaseOrder', 'tender', 'createdBy'])),
            message: 'Contract terminated successfully.',
        );
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/contracts/{contract}/documents
    // -------------------------------------------------------------------------

    /**
     * @OA\Post(path="/contracts/{contract}/documents", operationId="uploadContractDocument", tags={"Contracts"}, summary="Upload document to contract",
     *     description="Accepted document types: performance_bond, signed_contract, amendment, other. Max 10 MB.",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/XTenantID"), @OA\Parameter(ref="#/components/parameters/XRequestID"),
     *     @OA\Parameter(name="contract", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *     @OA\RequestBody(required=true, @OA\MediaType(mediaType="multipart/form-data", @OA\Schema(required={"file","document_type"}, @OA\Property(property="file", type="string", format="binary"), @OA\Property(property="document_type", type="string", enum={"performance_bond","signed_contract","amendment","other"})))),
     *     @OA\Response(response=201, description="Document uploaded.", @OA\JsonContent(@OA\Property(property="success", type="boolean", example=true), @OA\Property(property="data", type="object"), @OA\Property(property="message", type="string"), @OA\Property(property="errors", nullable=true, example=null), @OA\Property(property="meta", nullable=true, example=null))),
     *     @OA\Response(response=422, description="Invalid file.", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     *
     * Upload and attach a document to a contract.
     *
     * Requirements: 11.2, 11.7
     */
    public function uploadDocument(UploadContractDocumentRequest $request, Contract $contract): JsonResponse
    {
        $user = Auth::guard('api')->user();
        $file = $request->file('file');

        // Store the file under contracts/{contract_id}/
        $path = $file->store("contracts/{$contract->id}", 'local');

        try {
            $document = $this->service->uploadDocument($contract, [
                'document_type' => $request->validated()['document_type'],
                'file_path'     => $path,
                'file_name'     => $file->getClientOriginalName(),
            ], $user);
        } catch (\Throwable $e) {
            // Clean up stored file on failure
            Storage::disk('local')->delete($path);

            return $this->error('Failed to upload document: ' . $e->getMessage(), 500);
        }

        $document->load('uploadedBy');

        return $this->created(
            data:    new ContractDocumentResource($document),
            message: 'Document uploaded successfully.',
        );
    }

    // -------------------------------------------------------------------------
    // PUT /api/v1/contracts/{contract} — not used directly; amend via /amend
    // -------------------------------------------------------------------------

    /**
     * Not used — contract updates go through the /amend endpoint which
     * requires a documented reason and creates a version history entry.
     *
     * Returns 405 Method Not Allowed.
     */
    public function update(Request $request, Contract $contract): JsonResponse
    {
        return $this->error(
            'Direct update is not supported. Use POST /contracts/{id}/amend to amend a contract with a documented reason.',
            405,
        );
    }

    // -------------------------------------------------------------------------
    // DELETE /api/v1/contracts/{contract}
    // -------------------------------------------------------------------------

    /**
     * Soft-delete a draft contract.
     *
     * Only draft contracts may be deleted; active/terminated contracts must be
     * terminated first.
     */
    public function destroy(Contract $contract): JsonResponse
    {
        if ($contract->status !== 'draft') {
            return $this->error(
                "Only draft contracts can be deleted. Use the terminate endpoint to end an active contract.",
                422,
                ['general' => ['Only draft contracts can be deleted.']],
            );
        }

        $contract->delete();

        return $this->noContent();
    }
}
