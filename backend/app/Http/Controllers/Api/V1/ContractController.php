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
     * Return a paginated list of contracts, with optional filters.
     *
     * Query parameters:
     *   status       — filter by status value
     *   supplier_id  — filter by supplier UUID
     *   date_from    — filter created_at >= date (Y-m-d)
     *   date_to      — filter created_at <= date (Y-m-d)
     *   per_page     — results per page (default 20, max 100)
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
     * Activate a contract (draft → active).
     *
     * Blocked if no performance bond document is uploaded — returns HTTP 422
     * with a descriptive error message.
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
     * Amend a contract with a documented reason.
     *
     * Creates a version history entry (ContractAmendment) with before/after
     * snapshot and the reason.
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
     * Terminate a contract (active → terminated).
     *
     * A termination reason is required.
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
     * Upload and attach a document to a contract.
     *
     * Accepted document types: performance_bond, signed_contract, amendment, other.
     * Max file size: 10 MB.
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
