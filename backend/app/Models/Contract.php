<?php

namespace App\Models;

use App\Traits\HasAuditLog;
use App\Traits\HasTenantScope;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contract extends Model
{
    use HasFactory, HasTenantScope, HasUuids, HasAuditLog, SoftDeletes;

    protected $fillable = [
        'contract_number',
        'purchase_order_id',
        'tender_id',
        'supplier_id',
        'title',
        'scope',
        'total_value',
        'consumed_value',
        'currency',
        'start_date',
        'end_date',
        'payment_terms',
        'status',
        'termination_reason',
        'created_by',
    ];

    protected $casts = [
        'total_value'    => 'string',
        'consumed_value' => 'string',
        'start_date'     => 'date',
        'end_date'       => 'date',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function tender(): BelongsTo
    {
        return $this->belongsTo(Tender::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function amendments(): HasMany
    {
        return $this->hasMany(ContractAmendment::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ContractDocument::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }
}
