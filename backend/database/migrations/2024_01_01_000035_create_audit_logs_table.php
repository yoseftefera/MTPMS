<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * audit_logs is an append-only immutable log of all state-changing
     * operations across the platform. Records are NEVER updated or deleted —
     * this is enforced by having only created_at (no updated_at, no
     * deleted_at). tenant_id and user_id are stored as plain UUIDs (not
     * foreign keys) so that cross-tenant system actions and records for
     * deleted users are preserved. before_data / after_data capture the
     * full JSON snapshot of the entity before and after the change.
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->uuid('user_id')->nullable();
            $table->string('user_role', 100)->nullable();
            $table->string('action', 100);
            $table->string('entity_type', 100);
            $table->uuid('entity_id')->nullable();
            $table->json('before_data')->nullable();
            $table->json('after_data')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('request_id', 100)->nullable();
            // Append-only: created_at only — NO updated_at, NO deleted_at
            $table->timestamp('created_at')->useCurrent();

            // Indexes — no FK constraints (cross-tenant / deleted-user safety)
            $table->index('tenant_id');
            $table->index('user_id');
            $table->index('action');
            $table->index('entity_type');
            $table->index('created_at');
            $table->index('ip_address');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
