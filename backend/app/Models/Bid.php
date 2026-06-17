<?php

namespace App\Models;

use App\Traits\HasAuditLog;
use App\Traits\HasTenantScope;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Bid extends Model
{
    use HasFactory, HasTenantScope, HasUuids, HasAuditLog;

    protected $fillable = [
        'tenant_id',
        'tender_id',
        'supplier_id',
        'total_amount',
        'currency',
        'delivery_days',
        'technical_notes',
        'status',
        'submitted_at',
        'weighted_score',
    ];

    protected $casts = [
        'total_amount'   => 'string',
        'weighted_score' => 'string',
        'submitted_at'   => 'datetime',
    ];

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function tender(): BelongsTo
    {
        return $this->belongsTo(Tender::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(BidDocument::class);
    }

    public function evaluations(): HasMany
    {
        return $this->hasMany(BidEvaluation::class);
    }

    public function purchaseOrder(): HasOne
    {
        return $this->hasOne(PurchaseOrder::class);
    }
}
