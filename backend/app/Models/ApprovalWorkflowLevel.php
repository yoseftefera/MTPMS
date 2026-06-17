<?php

namespace App\Models;

use App\Traits\HasTenantScope;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ApprovalWorkflowLevel extends Model
{
    use HasFactory, HasTenantScope, HasUuids;

    protected $fillable = [
        'workflow_id',
        'level_order',
        'approver_type',
        'approver_role',
        'approver_user_id',
        'is_parallel',
        'escalation_hours',
    ];

    protected $casts = [
        'level_order'      => 'integer',
        'is_parallel'      => 'boolean',
        'escalation_hours' => 'integer',
    ];

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(ApprovalWorkflow::class, 'workflow_id');
    }

    public function approverUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_user_id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(Approval::class, 'level_id');
    }
}
