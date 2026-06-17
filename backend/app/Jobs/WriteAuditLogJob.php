<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Async audit log writer dispatched on the 'default' queue.
 *
 * Design requirement: max 5-second write latency.
 * The audit_logs table is append-only — no UPDATE or DELETE operations.
 */
class WriteAuditLogJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 10;

    public function __construct(
        private readonly ?string $tenantId,
        private readonly ?string $userId,
        private readonly ?string $userRole,
        private readonly string $actionType,
        private readonly string $entityType,
        private readonly ?string $entityId,
        private readonly ?array $before,
        private readonly ?array $after,
        private readonly string $ipAddress,
        private readonly ?string $requestId,
    ) {
        $this->onQueue('default');
    }

    /**
     * Execute the job — insert an immutable audit log record.
     */
    public function handle(): void
    {
        DB::table('audit_logs')->insert([
            'id'          => \Illuminate\Support\Str::uuid()->toString(),
            'tenant_id'   => $this->tenantId,
            'user_id'     => $this->userId,
            'user_role'   => $this->userRole,
            'action'      => $this->actionType,
            'entity_type' => $this->entityType,
            'entity_id'   => $this->entityId,
            'before_data' => $this->before ? json_encode($this->before) : null,
            'after_data'  => $this->after  ? json_encode($this->after)  : null,
            'ip_address'  => $this->ipAddress,
            'request_id'  => $this->requestId,
            'created_at'  => now()->toDateTimeString(),
        ]);
    }
}
