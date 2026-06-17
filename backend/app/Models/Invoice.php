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

class Invoice extends Model
{
    use HasFactory, HasTenantScope, HasUuids, HasAuditLog, SoftDeletes;

    protected $fillable = [
        'invoice_number',
        'supplier_id',
        'purchase_order_id',
        'contract_id',
        'total_amount',
        'paid_amount',
        'currency',
        'invoice_date',
        'due_date',
        'status',
        'rejection_reason',
        'submitted_at',
    ];

    protected $casts = [
        'total_amount'  => 'string',
        'paid_amount'   => 'string',
        'invoice_date'  => 'date',
        'due_date'      => 'date',
        'submitted_at'  => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
