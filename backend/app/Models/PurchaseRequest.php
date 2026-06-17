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

class PurchaseRequest extends Model
{
    use HasFactory, HasTenantScope, HasUuids, HasAuditLog, GeneratesDocumentNumber, SoftDeletes;

    protected $fillable = [
        'pr_number',
        'department_id',
        'submitted_by',
        'status',
        'title',
        'description',
        'estimated_total',
        'currency',
        'required_date',
        'submitted_at',
    ];

    protected $casts = [
        'estimated_total' => 'string',
        'required_date'   => 'date',
        'submitted_at'    => 'datetime',
    ];

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseRequestItem::class);
    }

    public function history(): HasMany
    {
        return $this->hasMany(PurchaseRequestHistory::class);
    }

    public function purchaseOrder(): HasOne
    {
        return $this->hasOne(PurchaseOrder::class);
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(Approval::class, 'document_id')
            ->where('document_type', 'purchase_request');
    }
}
