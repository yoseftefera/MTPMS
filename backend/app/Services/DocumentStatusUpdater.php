<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Invoice;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Tender;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * DocumentStatusUpdater — resolves a document model from its type + ID and
 * updates its status column.
 *
 * This helper is intentionally kept thin: it only handles the model lookup
 * and the status field update. Business-rule validation (allowed transitions,
 * side effects such as budget encumbrance) lives in the owning service.
 *
 * Supported document types (enum values from the approval_workflows table):
 *   purchase_request | tender | purchase_order | contract | invoice
 *
 * Requirements: 6.3, 6.4, 6.5
 */
class DocumentStatusUpdater
{
    /**
     * Valid document-type → Eloquent model class map.
     *
     * @var array<string, class-string<Model>>
     */
    private const MODEL_MAP = [
        'purchase_request' => PurchaseRequest::class,
        'tender'           => Tender::class,
        'purchase_order'   => PurchaseOrder::class,
        'contract'         => Contract::class,
        'invoice'          => Invoice::class,
    ];

    /**
     * Resolve the correct model class from $documentType, load the record,
     * and update its `status` column to $status.
     *
     * Uses withoutGlobalScopes() so the update can safely execute from console
     * commands or queued jobs where no app('tenant') context is hydrated. The
     * caller (ApprovalWorkflowService) is responsible for ensuring the document
     * belongs to the active tenant before calling this method.
     *
     * @param  string  $documentType  One of: purchase_request, tender, purchase_order, contract, invoice
     * @param  string  $documentId    UUID of the document record
     * @param  string  $status        Target status value
     *
     * @throws InvalidArgumentException  when $documentType is not in the supported set
     * @throws \RuntimeException         when the document record cannot be found
     */
    public function updateStatus(string $documentType, string $documentId, string $status): void
    {
        $modelClass = self::MODEL_MAP[$documentType] ?? null;

        if ($modelClass === null) {
            throw new InvalidArgumentException(
                "Unsupported document type '{$documentType}'. "
                . 'Allowed values: ' . implode(', ', array_keys(self::MODEL_MAP)) . '.'
            );
        }

        /** @var Model|null $document */
        $document = $modelClass::withoutGlobalScopes()->find($documentId);

        if ($document === null) {
            throw new \RuntimeException(
                "Document of type '{$documentType}' with ID '{$documentId}' was not found."
            );
        }

        $document->update(['status' => $status]);
    }
}
