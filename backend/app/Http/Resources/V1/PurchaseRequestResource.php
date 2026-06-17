<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * PurchaseRequestResource — transforms a PurchaseRequest model into the standard API shape.
 *
 * - Monetary amounts (estimated_total) serialized as strings with 2 decimal places.
 * - Conditionally includes items, history, department, and submitter when loaded.
 * - Provides a human-readable `status_label` computed field.
 *
 * Requirements: 5.8, 5.10
 */
class PurchaseRequestResource extends JsonResource
{
    /**
     * Human-readable labels for each PR status value.
     */
    private const STATUS_LABELS = [
        'draft'              => 'Draft',
        'pending_approval'   => 'Pending Approval',
        'approved'           => 'Approved',
        'rejected'           => 'Rejected',
        'cancelled'          => 'Cancelled',
        'revision_required'  => 'Revision Required',
        'completed'          => 'Completed',
    ];

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'tenant_id'       => $this->tenant_id,
            'pr_number'       => $this->pr_number,
            'title'           => $this->title,
            'description'     => $this->description,
            'status'          => $this->status,
            'status_label'    => self::STATUS_LABELS[$this->status] ?? ucfirst($this->status),
            'currency'        => $this->currency,
            'estimated_total' => number_format((float) $this->estimated_total, 2, '.', ''),
            'required_date'   => $this->required_date?->toDateString(),
            'submitted_at'    => $this->submitted_at?->toIso8601String(),

            // Foreign key IDs
            'department_id'   => $this->department_id,
            'submitted_by'    => $this->submitted_by,

            // Conditionally loaded relationships
            'department'      => $this->whenLoaded('department', fn () => [
                'id'   => $this->department->id,
                'name' => $this->department->name,
                'code' => $this->department->code,
            ]),
            'submitter'       => $this->whenLoaded('submittedBy', fn () => [
                'id'    => $this->submittedBy->id,
                'name'  => $this->submittedBy->name,
                'email' => $this->submittedBy->email,
            ]),
            'items'           => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id'                    => $item->id,
                'description'           => $item->description,
                'quantity'              => number_format((float) $item->quantity, 2, '.', ''),
                'unit_of_measure'       => $item->unit_of_measure,
                'estimated_unit_price'  => number_format((float) $item->estimated_unit_price, 2, '.', ''),
                'budget_code'           => $item->budget_code,
                'line_total'            => number_format(
                    (float) bcmul((string) $item->quantity, (string) $item->estimated_unit_price, 2),
                    2, '.', ''
                ),
            ])),
            'history'         => $this->whenLoaded('history', fn () => $this->history->map(fn ($entry) => [
                'id'           => $entry->id,
                'action'       => $entry->action,
                'from_status'  => $entry->from_status,
                'to_status'    => $entry->to_status,
                'comment'      => $entry->comment,
                'performed_by' => $entry->performed_by,
                'created_at'   => $entry->created_at?->toIso8601String(),
            ])),

            'created_at'      => $this->created_at?->toIso8601String(),
            'updated_at'      => $this->updated_at?->toIso8601String(),
            'deleted_at'      => $this->deleted_at?->toIso8601String(),
        ];
    }
}
