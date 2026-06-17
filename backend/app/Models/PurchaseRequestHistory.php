<?php

namespace App\Models;

use App\Traits\HasTenantScope;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseRequestHistory extends Model
{
    use HasFactory, HasTenantScope, HasUuids;

    protected $table = 'purchase_request_history';

    /**
     * This table only has created_at (no updated_at).
     */
    public $timestamps = false;

    const CREATED_AT = 'created_at';

    protected $fillable = [
        'purchase_request_id',
        'action',
        'from_status',
        'to_status',
        'comment',
        'performed_by',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class);
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
