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

class Tender extends Model
{
    use HasFactory, HasTenantScope, HasUuids, HasAuditLog, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'reference_number',
        'title',
        'description',
        'category',
        'tender_type',
        'estimated_value',
        'submission_deadline',
        'status',
        'evaluation_status',
        'winning_bid_id',
        'winner_justification',
        'evaluation_mode',
        'assigned_evaluators',
        'created_by',
        'published_at',
        'cancellation_reason',
    ];

    protected $casts = [
        'estimated_value'     => 'string',
        'submission_deadline' => 'datetime',
        'published_at'        => 'datetime',
        'assigned_evaluators' => 'array',
    ];

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(TenderDocument::class);
    }

    public function bids(): HasMany
    {
        return $this->hasMany(Bid::class);
    }

    public function evaluationCriteria(): HasMany
    {
        return $this->hasMany(BidEvaluationCriteria::class);
    }

    public function winningBid(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Bid::class, 'winning_bid_id');
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }
}
