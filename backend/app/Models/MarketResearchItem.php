<?php

namespace App\Models;

use App\Traits\HasTenantScope;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketResearchItem extends Model
{
    use HasFactory, HasTenantScope, HasUuids;

    protected $fillable = [
        'market_research_id',
        'supplier_id',
        'item_name',
        'description',
        'estimated_price',
        'currency',
        'notes',
    ];

    protected $casts = [
        'estimated_price' => 'string',
    ];

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function marketResearch(): BelongsTo
    {
        return $this->belongsTo(MarketResearch::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
