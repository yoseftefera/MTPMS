<?php

namespace App\Models;

use App\Traits\HasTenantScope;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierPerformance extends Model
{
    use HasFactory, HasTenantScope, HasUuids;

    protected $table = 'supplier_performance';

    /**
     * This table only has created_at (no updated_at).
     */
    public $timestamps = false;

    const CREATED_AT = 'created_at';

    protected $fillable = [
        'tenant_id',
        'supplier_id',
        'metric_type',
        'value',
        'reference_type',
        'reference_id',
        'recorded_at',
    ];

    protected $casts = [
        'value'       => 'string',
        'recorded_at' => 'datetime',
        'created_at'  => 'datetime',
    ];

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
