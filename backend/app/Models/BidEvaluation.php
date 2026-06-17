<?php

namespace App\Models;

use App\Traits\HasAuditLog;
use App\Traits\HasTenantScope;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BidEvaluation extends Model
{
    use HasFactory, HasTenantScope, HasUuids, HasAuditLog;

    protected $fillable = [
        'tenant_id',
        'bid_id',
        'criteria_id',
        'evaluator_id',
        'score',
        'comment',
        'is_finalized',
    ];

    protected $casts = [
        'score'        => 'string',
        'is_finalized' => 'boolean',
    ];

    public function bid(): BelongsTo
    {
        return $this->belongsTo(Bid::class);
    }

    public function criteria(): BelongsTo
    {
        return $this->belongsTo(BidEvaluationCriteria::class, 'criteria_id');
    }

    public function evaluator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'evaluator_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
