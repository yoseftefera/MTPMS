<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ContractResource — transforms a Contract model into the standard API shape.
 *
 * - Monetary amounts serialized as strings with 2 decimal places.
 * - Conditionally includes amendments, documents, supplier, and creator when loaded.
 * - Provides a human-readable `status_label` and a `consumption_percentage` computed field.
 *
 * Requirements: 11.1, 11.2, 11.5, 11.6, 11.7, 11.9
 */
class ContractResource extends JsonResource
{
    /**
     * Human-readable labels for each contract status value.
     */
    private const STATUS_LABELS = [
        'draft'      => 'Draft',
        'active'     => 'Active',
        'terminated' => 'Terminated',
        'expired'    => 'Expired',
    ];

    public function toArray(Request $request): array
    {
        $totalValue    = (float) ($this->total_value ?? 0);
        $consumedValue = (float) ($this->consumed_value ?? 0);

        $consumptionPercent = $totalValue > 0
            ? round(($consumedValue / $totalValue) * 100, 2)
            : 0.0;

        return [
            'id'              => $this->id,
            'tenant_id'       => $this->tenant_id,
            'contract_number' => $this->contract_number,
            'status'          => $this->status,
            'status_label'    => self::STATUS_LABELS[$this->status] ?? ucfirst($this->status),
            'title'           => $this->title,
            'scope'           => $this->scope,
            'currency'        => $this->currency,
            'total_value'     => number_format($totalValue, 2, '.', ''),
            'consumed_value'  => number_format($consumedValue, 2, '.', ''),
            'consumption_percentage' => $consumptionPercent,
            'payment_terms'   => $this->payment_terms,

            // Linked documents
            'purchase_order_id' => $this->purchase_order_id,
            'tender_id'         => $this->tender_id,
            'supplier_id'       => $this->supplier_id,
            'created_by'        => $this->created_by,

            // Dates
            'start_date'        => $this->start_date?->toDateString(),
            'end_date'          => $this->end_date?->toDateString(),

            // Reason fields
            'termination_reason' => $this->termination_reason ?? null,

            // Conditionally loaded relationships
            'supplier'    => $this->whenLoaded('supplier', fn () => [
                'id'                => $this->supplier->id,
                'organization_name' => $this->supplier->organization_name,
                'contact_email'     => $this->supplier->contact_email,
                'contact_phone'     => $this->supplier->contact_phone,
            ]),
            'purchase_order' => $this->whenLoaded('purchaseOrder', fn () => [
                'id'        => $this->purchaseOrder->id,
                'po_number' => $this->purchaseOrder->po_number,
            ]),
            'tender' => $this->whenLoaded('tender', fn () => [
                'id'    => $this->tender->id,
                'title' => $this->tender->title,
            ]),
            'creator' => $this->whenLoaded('createdBy', fn () => [
                'id'    => $this->createdBy->id,
                'name'  => $this->createdBy->name,
                'email' => $this->createdBy->email,
            ]),
            'amendments' => $this->whenLoaded('amendments', fn () =>
                ContractAmendmentResource::collection($this->amendments)
            ),
            'documents' => $this->whenLoaded('documents', fn () =>
                ContractDocumentResource::collection($this->documents)
            ),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
