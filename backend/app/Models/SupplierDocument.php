<?php

namespace App\Models;

use App\Traits\HasTenantScope;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SupplierDocument extends Model
{
    use HasFactory, HasTenantScope, HasUuids, SoftDeletes;

    /**
     * This table only has created_at (no updated_at).
     */
    public $timestamps = false;

    const CREATED_AT = 'created_at';

    protected $fillable = [
        'tenant_id',
        'supplier_id',
        'document_type',
        'file_path',
        'file_name',
        'expires_at',
        'version',
        'uploaded_by',
    ];

    protected $casts = [
        'expires_at' => 'date',
        'version'    => 'integer',
        'created_at' => 'datetime',
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

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
