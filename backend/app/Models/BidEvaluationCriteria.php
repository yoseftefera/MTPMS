<?php

namespace App\Models;

use App\Traits\HasTenantScope;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BidEvaluationCriteria extends Model
{
    use HasFactory, HasTenantScope, HasUuids;

    protected $table = 'bid_evaluation_criteria';

    protected $fillable = [
        'tenant_id',
        'tender_id',
        'name',
        'weight',
        'description',
        'max_score',
    ];

    protected $casts = [
        'weight'    => 'string',
        'max_score' => 'string',
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

    public function evaluations(): HasMany
    {
        return $this->hasMany(BidEvaluation::class, 'criteria_id');
    }
}
