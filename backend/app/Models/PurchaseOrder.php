<?php

namespace App\Models;

use App\Traits\GeneratesDocumentNumber;
use App\Traits\HasAuditLog;
use App\Traits\HasTenantScope;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseOrder extends Model
{
    use HasFactory, HasTenantScope, HasUuids, HasAuditLog, GeneratesDocumentNumber, SoftDeletes;

    protected $fillable = [
        'po_number',
        'purchase_request_id',
        'bid_id',
        'supplier_id',
        'department_id',
        'status',
        'total_amount',
        'currency',
        'delivery_address',
        'required_delivery_date',
        'issued_at',
        'accepted_at',
        'rejection_reason',
        'cancellation_reason',
        'notes',
        'pending_supplier_acknowledgment',
        'created_by',
    ];

    protected $casts = [
        'total_amount'                   => 'string',
        'required_delivery_date'         => 'date',
        'issued_at'                      => 'datetime',
        'accepted_at'                    => 'datetime',
        'pending_supplier_acknowledgment'=> 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class);
    }

    public function bid(): BelongsTo
    {
        return $this->belongsTo(Bid::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function goodsReceipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class);
    }

    public function contract(): HasOne
    {
        return $this->hasOne(Contract::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }
}
